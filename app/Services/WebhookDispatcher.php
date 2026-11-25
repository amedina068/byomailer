<?php

namespace App\Services;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class WebhookDispatcher
{
    /**
     * Fan a domain event out to every active client endpoint subscribed to it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(WebhookEvent $event, int $clientId, array $payload): void
    {
        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event->value));

        if ($endpoints->isEmpty()) {
            return;
        }

        // Delivery rows + queued jobs commit atomically; afterCommit on the job
        // defers the actual queue push until this transaction commits.
        DB::transaction(function () use ($endpoints, $event, $clientId, $payload): void {
            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::create([
                    'client_id' => $clientId,
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_type' => $event->value,
                    'payload' => $payload,
                    'status' => WebhookDeliveryStatus::Pending,
                    'dispatched_at' => now(),
                ]);

                DeliverWebhookJob::dispatch($endpoint->id, $delivery->id);
            }
        });
    }

    /**
     * Queue a one-off sample delivery so the customer can verify their receiver.
     */
    public function dispatchTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'client_id' => $endpoint->client_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => 'webhook.test',
            'payload' => [
                'event' => 'webhook.test',
                'occurred_at' => now()->toIso8601String(),
                'message' => 'This is a test delivery from BYOMailer.',
            ],
            'status' => WebhookDeliveryStatus::Pending,
            'dispatched_at' => now(),
        ]);

        DeliverWebhookJob::dispatch($endpoint->id, $delivery->id);

        return $delivery;
    }
}
