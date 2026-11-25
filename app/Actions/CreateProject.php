<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\User;

class CreateProject
{
    /**
     * Create a project in the client of the user. The user is the author.
     */
    public function handle(User $user, string $name): Project
    {
        return Project::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
