<?php

namespace App\Actions;

use App\Models\Client;

class UpdateAccountSettings
{
    /**
     * Save the account settings of the client. Only the keys that are in
     * the validated data change. An empty postal address is saved as null.
     *
     * @param  array{timezone?: string, sunset_inactive_days?: int|string|null, postal_address?: string|null}  $validated
     */
    public function handle(Client $client, array $validated): Client
    {
        $changes = [];

        if (array_key_exists('timezone', $validated)) {
            $changes['timezone'] = $validated['timezone'];
        }

        if (array_key_exists('sunset_inactive_days', $validated)) {
            $days = $validated['sunset_inactive_days'];
            $changes['sunset_inactive_days'] = $days === null || $days === '' ? null : (int) $days;
        }

        if (array_key_exists('postal_address', $validated)) {
            $address = trim((string) $validated['postal_address']);
            $changes['postal_address'] = $address === '' ? null : $address;
        }

        if ($changes !== []) {
            $client->update($changes);
        }

        return $client;
    }
}
