<?php

namespace App\Actions;

use App\Models\WebhookEndpoint;

class UpdateWebhookEndpoint
{
    /**
     * Change the description, or turn the endpoint on or off. A key that is
     * not in `$attributes` keeps its value.
     *
     * When `active` is the current status, the status fields do not change.
     * Thus a disable keeps the reason of an endpoint that failed too often,
     * and an enable of an active endpoint keeps its failure count.
     *
     * The caller checks that the user is an admin.
     *
     * @param  array{description?: string|null, active?: bool}  $attributes
     */
    public function handle(WebhookEndpoint $endpoint, array $attributes): WebhookEndpoint
    {
        if (array_key_exists('description', $attributes)) {
            $endpoint->description = $attributes['description'];
        }

        $active = $attributes['active'] ?? null;

        if ($active === null || $active === $endpoint->active) {
            $endpoint->save();
        } elseif ($active) {
            $endpoint->activate();
        } else {
            $endpoint->deactivate();
        }

        return $endpoint;
    }
}
