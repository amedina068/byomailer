<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Client;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function dispatch(int $clientId, WebhookEvent $event = WebhookEvent::ContactSubscribed): void
    {
        app(WebhookDispatcher::class)->dispatch($event, $clientId, ['event' => $event->value]);
    }

    public function test_creates_delivery_and_queues_job_for_matching_active_endpoint(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create(['events' => ['contact.subscribed'], 'active' => true]);

        $this->dispatch($endpoint->client_id);

        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_skips_inactive_endpoint(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->inactive()->create(['events' => ['contact.subscribed']]);

        $this->dispatch($endpoint->client_id);

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_skips_endpoint_not_subscribed_to_event(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create(['events' => ['newsletter.sent']]);

        $this->dispatch($endpoint->client_id, WebhookEvent::ContactSubscribed);

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());
    }

    public function test_does_not_leak_across_tenants(): void
    {
        Queue::fake();
        $endpointA = WebhookEndpoint::factory()->create(['events' => ['contact.subscribed']]);
        $otherClient = Client::factory()->create();

        $this->dispatch($otherClient->id);

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_fans_out_to_multiple_matching_endpoints(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create(['events' => ['contact.subscribed']]);
        WebhookEndpoint::factory()->create([
            'client_id' => $endpoint->client_id,
            'user_id' => $endpoint->user_id,
            'events' => ['contact.subscribed', 'newsletter.sent'],
        ]);

        $this->dispatch($endpoint->client_id);

        $this->assertSame(2, WebhookDelivery::withoutGlobalScopes()->count());
        Queue::assertPushed(DeliverWebhookJob::class, 2);
    }
}
