<?php

namespace App\Actions;

use App\Models\User;
use App\Models\WebhookEndpoint;

class CreateWebhookEndpoint
{
    /**
     * Create a webhook endpoint in the client of the user. The user is the
     * author. The model makes the signing secret.
     *
     * The caller checks that the user is an admin and that the URL is safe.
     *
     * @param  list<string>  $events
     */
    public function handle(User $user, string $url, array $events, ?string $description = null): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'url' => $url,
            'events' => $events,
            'description' => $description,
        ]);
    }
}
