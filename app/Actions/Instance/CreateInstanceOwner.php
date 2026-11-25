<?php

namespace App\Actions\Instance;

use App\Actions\CreateAccount;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\InstanceSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the owner account of a self-hosted instance.
 *
 * The `byomailer:install` command and the web setup page both use this action.
 * The owner gets a new client, the team admin role and a verified email.
 */
class CreateInstanceOwner
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateAccount $createAccount) {}

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, timezone?: string|null}  $input
     */
    public function create(array $input): User
    {
        return DB::transaction(fn (): User => $this->createOwner($input));
    }

    /**
     * Create the owner only when the instance has no user yet. Two requests
     * can pass the first check at the same time. The lock and the second check
     * inside the transaction make sure that only one owner gets created.
     * Returns null when a user already exists.
     *
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, timezone?: string|null}  $input
     */
    public function createFirstOwner(array $input): ?User
    {
        return Cache::lock('byomailer:instance-setup', 30)->block(10, function () use ($input): ?User {
            return DB::transaction(function () use ($input): ?User {
                if (! InstanceSetup::isNeeded()) {
                    return null;
                }

                return $this->createOwner($input);
            });
        });
    }

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, timezone?: string|null}  $input
     */
    private function createOwner(array $input): User
    {
        $input['timezone'] = ($input['timezone'] ?? null) ?: 'UTC';
        $input['password_confirmation'] ??= $input['password'];

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = $this->createAccount->handle([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'timezone' => $input['timezone'],
        ]);

        $user->forceFill(['is_owner' => true, 'email_verified_at' => now()])->save();

        return $user;
    }
}
