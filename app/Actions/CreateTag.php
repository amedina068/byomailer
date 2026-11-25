<?php

namespace App\Actions;

use App\Models\Tag;
use App\Models\User;

class CreateTag
{
    /**
     * Create a tag in the client of the user. The user is the author.
     */
    public function handle(User $user, string $name): Tag
    {
        return Tag::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
