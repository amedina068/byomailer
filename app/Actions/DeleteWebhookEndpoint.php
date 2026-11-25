<?php

namespace App\Actions;

use App\Models\WebhookEndpoint;

class DeleteWebhookEndpoint
{
    /**
     * Delete the endpoint.
     *
     * The caller checks that the user is an admin.
     */
    public function handle(WebhookEndpoint $endpoint): void
    {
        $endpoint->delete();
    }
}
