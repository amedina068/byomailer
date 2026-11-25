<?php

namespace App\Actions;

use App\Models\EmailCredential;
use App\Models\User;

class SaveSesCredential
{
    /**
     * Save the SES credential of the client of the user.
     *
     * A client has one SES credential, keyed on `(client_id, type)`. An
     * existing row is updated in place, so the SNS topic, the tenant and the
     * other AWS columns stay. The first user who saves a credential owns it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, array $attributes): EmailCredential
    {
        $credential = EmailCredential::withoutGlobalScopes()->firstOrNew(
            ['client_id' => $user->client_id, 'type' => 'ses'],
            ['user_id' => $user->id],
        );

        $credential->fill($attributes)->save();

        return $credential;
    }
}
