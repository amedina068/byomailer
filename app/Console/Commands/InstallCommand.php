<?php

namespace App\Console\Commands;

use App\Actions\Instance\CreateInstanceOwner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'byomailer:install
        {--name= : The name of the owner}
        {--email= : The email of the owner}
        {--password= : The password of the owner}
        {--timezone=UTC : The timezone of the account}
        {--promote= : Make the existing user with this email an owner, and create no account}
        {--prepare : Prepare the instance only, and create no account}';

    protected $description = 'Prepare this instance and create the owner account';

    public function handle(CreateInstanceOwner $owners): int
    {
        $this->prepareStorageLink();

        if ($this->option('prepare')) {
            return self::SUCCESS;
        }

        if ($email = $this->option('promote')) {
            return $this->promote((string) $email);
        }

        if (User::query()->exists()) {
            $this->error('This install already has users. The install command only creates the first user. To make an existing user an owner, use --promote.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $password = $this->option('password') ?: password('Password', required: true);

        try {
            $user = $owners->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'timezone' => $this->option('timezone') ?: 'UTC',
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Created the owner {$user->email}. Log in at ".rtrim((string) config('app.url'), '/').'/login.');
        $this->warn('Back up APP_KEY now. BYOMailer encrypts the AWS credentials with it. If you lose APP_KEY, you must enter the credentials again.');

        return self::SUCCESS;
    }

    private function promote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_owner' => true])->save();

        $this->info("{$user->email} is now an owner of this instance.");

        return self::SUCCESS;
    }

    /**
     * The public disk serves the uploaded images through public/storage.
     */
    private function prepareStorageLink(): void
    {
        if (! file_exists(public_path('storage'))) {
            $this->callSilently('storage:link');
        }
    }
}
