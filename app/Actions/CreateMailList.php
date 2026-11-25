<?php

namespace App\Actions;

use App\Models\MailList;
use App\Models\User;

class CreateMailList
{
    /**
     * Create a list in the client of the user. The user is the author.
     */
    public function handle(User $user, string $name): MailList
    {
        return MailList::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
