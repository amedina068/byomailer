<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endpoint = WebhookEndpoint::factory();

        return [
            'webhook_endpoint_id' => $endpoint,
            'client_id' => fn (array $a) => WebhookEndpoint::withoutGlobalScopes()->find($a['webhook_endpoint_id'])?->client_id,
            'event_type' => 'contact.subscribed',
            'payload' => ['event' => 'contact.subscribed'],
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'dispatched_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => WebhookDeliveryStatus::Pending]);
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookDeliveryStatus::Success,
            'attempts' => 1,
            'response_status' => 200,
            'delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookDeliveryStatus::Failed,
            'attempts' => 5,
            'response_status' => 500,
            'error' => 'Server error',
        ]);
    }
}
