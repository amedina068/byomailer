<?php

namespace App\Actions;

use App\Models\WebhookEndpoint;

class RotateWebhookEndpointSecret
{
    /**
     * Give the endpoint a new signing secret. The old secret stops working
     * at once.
     *
     * The caller checks that the user is an admin.
     */
    public function handle(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $endpoint->update(['secret' => WebhookEndpoint::generateSecret()]);

        return $endpoint;
    }
}
