<?php

namespace App\Actions\Fortify;

use App\Actions\CreateAccount;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Jobs\SyncContactToByomailerList;
use App\Models\User;
use App\Services\ByomailerClient;
use App\Support\Registration;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateAccount $createAccount) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        abort_unless(Registration::isOpen(), 404);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->registrationPasswordRules(),
        ])->validate();

        $user = $this->createAccount->handle([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'timezone' => $input['timezone'] ?? null,
        ]);

        if (app(ByomailerClient::class)->isConfigured()) {
            SyncContactToByomailerList::dispatch(
                $user->email,
                $user->name,
                ['free'],
            );
        }

        return $user;
    }
}
