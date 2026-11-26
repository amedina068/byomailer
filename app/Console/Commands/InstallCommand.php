<?php

namespace App\Console\Commands;

use App\Actions\Instance\CreateInstanceOwner;
use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\User;
use App\Support\Instance\ApiTokens;
use App\Support\Instance\LoginLink;
use App\Support\Instance\McpConnect;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\text;

/**
 * Prepares a new self-hosted instance and creates its owner.
 *
 * The command is safe to run again. It creates the storage link only when it
 * is missing, and it creates no second owner. A second run returns
 * `"status":"exists"` and exits 0.
 *
 * The owner gets a random password that nobody sees. The command returns a
 * one-time `login_url` instead. `--password` still works, but it is
 * deprecated: a password on the command line stays in the shell history.
 *
 * `--prepare` and its text output are part of the Docker entrypoint
 * (`deploy/docker/entrypoint.d/60-byomailer-setup.sh`). Keep them stable.
 */
class InstallCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'byomailer:install
        {--name= : The name of the owner}
        {--email= : The email of the owner}
        {--timezone=UTC : The timezone of the account}
        {--with-token= : Also create an API token with this name, and return the MCP connect commands}
        {--password= : Deprecated. The password of the owner. Without it, the owner logs in with the login_url}
        {--promote= : Make the existing user with this email an owner, and create no account}
        {--prepare : Prepare the instance only, and create no account}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Prepare this instance and create the owner account';

    /** @var array<string, string> */
    private array $prepared = [];

    public function handle(CreateInstanceOwner $owners): int
    {
        $this->prepareStorageLink();

        if ($this->option('prepare')) {
            return $this->respond(self::EXIT_OK, ['status' => 'prepared', 'prepared' => $this->prepared]);
        }

        if ($email = $this->option('promote')) {
            return $this->promote((string) $email);
        }

        if ($owner = User::query()->where('is_owner', true)->orderBy('id')->first()) {
            $this->note("An owner already exists ({$owner->email}). No account was created.");

            return $this->finish($owner, 'exists');
        }

        if (User::query()->exists()) {
            return $this->failWith(
                self::EXIT_CONFLICT,
                'users_without_owner',
                'This instance has users, but no owner. The install command creates only the first user.',
                'Run php artisan byomailer:install --promote=<email> to make an existing user the owner.',
            );
        }

        return $this->createOwner($owners);
    }

    private function createOwner(CreateInstanceOwner $owners): int
    {
        $input = $this->ownerInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            $owner = $owners->create($input);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->all();

            if (! $this->wantsJson()) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return $this->respond(self::EXIT_BAD_INPUT, ['status' => 'invalid'], array_map(
                fn (string $message): array => self::errorItem('invalid_input', $message, 'Correct the option and run the command again.'),
                $messages,
            ));
        }

        $this->note("Created the owner {$owner->email}.");
        $this->warnUser('Back up APP_KEY now. BYOMailer encrypts the AWS credentials with it. If you lose APP_KEY, you must enter the credentials again.');

        return $this->finish($owner, 'created');
    }

    /**
     * @return array{name: string, email: string, password: string, timezone: string}|int
     */
    private function ownerInput(): array|int
    {
        $interactive = $this->input->isInteractive() && ! $this->wantsJson();
        $name = $this->option('name') ?: ($interactive ? text('Name', required: true) : null);
        $email = $this->option('email') ?: ($interactive ? text('Email', required: true) : null);

        if (! $name || ! $email) {
            return $this->failWith(
                self::EXIT_BAD_INPUT,
                'missing_option',
                'Give --name and --email to create the owner.',
                'Run php artisan byomailer:install --name="Your Name" --email=you@example.com --json.',
            );
        }

        if ($this->option('password') !== null) {
            $this->warnUser('--password is deprecated. The password stays in the shell history. Use the login_url instead.');
        }

        return [
            'name' => (string) $name,
            'email' => (string) $email,
            // Nobody sees the random password. The owner logs in with the
            // one-time login URL, and can set a password in the settings.
            'password' => $this->option('password') ?? Str::password(40),
            'timezone' => $this->option('timezone') ?: 'UTC',
        ];
    }

    /**
     * Writes the result of a run that has an owner.
     */
    private function finish(User $owner, string $status): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $owner->loadMissing('client:id,name');

        $data = [
            'status' => $status,
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'account' => $owner->client ? ['id' => $owner->client->id, 'name' => $owner->client->name] : null,
            ],
            'app_url' => (string) config('app.url'),
            'login_url' => LoginLink::create($owner),
            'login_url_expires_in_minutes' => LoginLink::MINUTES,
            'password_set' => $status === 'created' ? $this->option('password') !== null : null,
            'next_steps' => [
                'ses_credentials_url' => "{$appUrl}/settings/email-credentials",
                'identities_url' => "{$appUrl}/settings/identities",
                'doctor' => 'php artisan byomailer:doctor --json',
            ],
            'prepared' => $this->prepared,
        ];

        if (($tokenName = $this->option('with-token')) !== null) {
            if (trim((string) $tokenName) === '') {
                return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_token_name', 'The --with-token option needs a name.', 'Run the command again with --with-token=agent.', $data);
            }

            $token = ApiTokens::create($owner, (string) $tokenName);
            $data['token'] = ApiTokens::describe($token->accessToken) + ['value' => $token->plainTextToken];
            $data['connect'] = McpConnect::summary($token->plainTextToken);
        }

        if (! $this->wantsJson()) {
            $this->printResult($data);
        }

        return $this->respond(self::EXIT_OK, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function printResult(array $data): void
    {
        $this->newLine();
        $this->line('  Log in one time with this link. It expires in '.LoginLink::MINUTES.' minutes:');
        $this->line("  {$data['login_url']}");
        $this->newLine();
        $this->line('  Then connect Amazon SES. Enter the AWS keys at:');
        $this->line("  {$data['next_steps']['ses_credentials_url']}");

        if (isset($data['token'])) {
            $this->newLine();
            $this->line("  API token \"{$data['token']['name']}\": {$data['token']['value']}");
            $this->line('  Keep this token now. The command does not show it again.');
            $this->newLine();
            $this->line('  Connect Claude Code. Export the token, then add the server:');
            $this->line('  '.$data['connect']['env']);
            $this->line('  '.$data['connect']['claude']);
        }

        $this->newLine();
    }

    private function promote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$email}.", 'Run php artisan byomailer:user:list to find the email.');
        }

        $changed = ! $user->isOwner();
        $user->forceFill(['is_owner' => true])->save();
        $this->note("{$user->email} is now an owner of this instance.");

        return $this->respond(self::EXIT_OK, ['status' => $changed ? 'promoted' : 'unchanged', 'user' => ['id' => $user->id, 'email' => $user->email]]);
    }

    /**
     * The public disk serves the uploaded images through public/storage.
     */
    private function prepareStorageLink(): void
    {
        if (file_exists(public_path('storage'))) {
            $this->prepared['storage_link'] = 'exists';

            return;
        }

        $this->callSilently('storage:link');
        $this->prepared['storage_link'] = 'created';
    }
}
