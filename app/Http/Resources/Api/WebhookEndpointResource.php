<?php

namespace App\Http\Resources\Api;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    private bool $withSecret = false;

    /**
     * Include the plaintext secret in the payload (store + regenerate only).
     */
    public function withSecret(): static
    {
        $this->withSecret = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events,
            'active' => (bool) $this->active,
            'disabled_reason' => $this->disabled_reason,
            'consecutive_failures' => (int) $this->consecutive_failures,
            'last_triggered_at' => optional($this->last_triggered_at)->toIso8601String(),
            'disabled_at' => optional($this->disabled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];

        if ($this->withSecret) {
            $data['secret'] = $this->secret;
        }

        return $data;
    }
}
