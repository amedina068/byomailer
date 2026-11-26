<?php

namespace App\Mcp\Concerns;

use App\Models\User;

trait FormatsAccountProfile
{
    /**
     * The profile settings that the Profile page shows. The timezone, the
     * sunset rule and the postal address belong to the client of the user.
     *
     * @return array{user_name: string, timezone: string|null, postal_address: string|null, has_postal_address: bool, sunset_inactive_days: int|null, can_edit: bool}
     */
    protected function accountProfile(User $user): array
    {
        $client = $user->client;
        $postalAddress = $client->postal_address;

        return [
            'user_name' => $user->name,
            'timezone' => $client->timezone,
            'postal_address' => $postalAddress,
            'has_postal_address' => trim((string) $postalAddress) !== '',
            'sunset_inactive_days' => $client->sunset_inactive_days,
            'can_edit' => $user->hasWriteAccess(),
        ];
    }
}
