<?php

namespace App\Actions;

use App\Models\Automation;
use App\Models\User;

class CreateAutomation
{
    /**
     * Create an automation in the client of the user. The user is the author.
     *
     * @param  array{name: string, type: string, condition: array<string, mixed>, actions: list<array<string, mixed>>}  $definition
     */
    public function handle(User $user, array $definition, bool $isLive): Automation
    {
        return Automation::create([
            ...$definition,
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'is_live' => $isLive,
        ]);
    }
}
