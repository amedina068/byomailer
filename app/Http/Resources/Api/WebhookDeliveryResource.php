<?php

namespace App\Http\Resources\Api;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
            'payload' => $this->payload,
            'dispatched_at' => optional($this->dispatched_at)->toIso8601String(),
            'delivered_at' => optional($this->delivered_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
