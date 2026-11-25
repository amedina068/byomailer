<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait ManagesTeamMembers
{
    private function ensureAdmin(User $user): void
    {
        abort_if(! $user->isAdmin(), 403);
    }

    private function ensureSameClient(User $currentUser, User $teamMember): void
    {
        abort_unless($currentUser->client_id === $teamMember->client_id, 404);
    }

    private function ensureClientKeepsAdmin(User $teamMember, string $errorKey = 'role'): void
    {
        $otherAdminExists = User::query()
            ->where('client_id', $teamMember->client_id)
            ->where('role', UserRole::Admin->value)
            ->where('id', '!=', $teamMember->id)
            ->exists();

        if (! $otherAdminExists) {
            throw ValidationException::withMessages([
                $errorKey => 'Your team must have at least one admin.',
            ]);
        }
    }
}
