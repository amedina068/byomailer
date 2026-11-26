<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\User;
use App\Support\Instance\ApiTokens;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Creates an API token for a user, the same token as Settings, API tokens.
 *
 * The default abilities are `read,write`, which open the REST API and `/mcp`.
 * The `instance_admin` ability opens `/mcp/admin` only. Only an owner can get
 * it, and it expires in 7 days or less.
 */
class TokenCreateCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'byomailer:token:create
        {--user= : The email of the user. The default is the first owner}
        {--name= : The name of the token}
        {--abilities=read,write : A comma-separated list: read, write, or instance_admin}
        {--expires=never : never, a duration (30d, 12h, 45m) or a date}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Create an API token for a user';

    public function handle(): int
    {
        $email = $this->option('user');
        $user = $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_owner', true)->orderBy('id')->first();

        if (! $user) {
            return $email
                ? $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$email}.", 'Run php artisan byomailer:user:list to find the email.')
                : $this->failWith(self::EXIT_BAD_INPUT, 'no_owner', 'This instance has no owner.', 'Run php artisan byomailer:install first, or give --user=<email>.');
        }

        $name = (string) $this->option('name');

        if (trim($name) === '') {
            return $this->failWith(self::EXIT_BAD_INPUT, 'missing_option', 'Give the token a name with --name.', 'Run the command again with --name=agent.');
        }

        try {
            $expiresAt = ApiTokens::parseExpiry($this->option('expires'));
            $token = ApiTokens::create($user, $name, explode(',', (string) $this->option('abilities')), $expiresAt);
        } catch (InvalidArgumentException $exception) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_token_request', $exception->getMessage(), 'Correct the option and run the command again.');
        }

        if (! $this->wantsJson()) {
            $this->components->info("Created the token \"{$name}\" for {$user->email}.");
            $this->line("  Token: {$token->plainTextToken}");
            $this->components->warn('Keep this token now. The command does not show it again.');
        }

        return $this->respond(self::EXIT_OK, ['token' => ApiTokens::describe($token->accessToken) + ['value' => $token->plainTextToken]]);
    }
}
