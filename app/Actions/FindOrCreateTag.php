<?php

namespace App\Actions;

use App\Models\Tag;
use App\Models\User;

class FindOrCreateTag
{
    /**
     * Return the tag with this name in the client of the user, or create it
     * with the user as the author. The client is explicit, so a job with no
     * tenant can call it. When a parallel request creates the same tag
     * first, firstOrCreate() catches the unique index error and returns
     * that tag.
     */
    public function handle(User $user, string $name): Tag
    {
        return Tag::withoutGlobalScopes()->firstOrCreate(
            ['client_id' => $user->client_id, 'name' => $name],
            ['user_id' => $user->id],
        );
    }
}
