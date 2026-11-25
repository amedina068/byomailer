<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Str;

class CreateAccount
{
    public function __construct(private SeedClientDefaultTemplate $seedClientDefaultTemplate) {}

    /**
     * Create a client and its first admin user, then seed the default email template.
     *
     * @param  array{name: string, email: string, password: string, timezone?: string|null}  $input
     */
    public function handle(array $input): User
    {
        $client = Client::create([
            'name' => $input['name'],
            'slug' => Str::slug($input['name']).'-'.Str::lower(Str::random(6)),
            'email' => $input['email'],
            'timezone' => $input['timezone'] ?? 'UTC',
        ]);

        $user = $client->users()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => UserRole::Admin->value,
        ]);

        $this->seedClientDefaultTemplate->handle($client, $user);

        return $user;
    }
}
