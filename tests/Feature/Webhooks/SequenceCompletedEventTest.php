<?php

namespace Tests\Feature\Webhooks;

use App\Events\ContactSequenceFinished;
use App\Listeners\WebhookEventListener;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenceCompletedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_finishing_sequence_creates_sequence_completed_delivery(): void
    {
        Queue::fake();
        $sequence = Sequence::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $sequence->client_id]);
        WebhookEndpoint::factory()->create([
            'client_id' => $sequence->client_id,
            'events' => ['sequence.completed'],
        ]);

        // Run the listener as the queued worker would.
        app(WebhookEventListener::class)->handle(new ContactSequenceFinished($contact, $sequence));

        $delivery = WebhookDelivery::withoutGlobalScopes()->first();
        $this->assertNotNull($delivery);
        $this->assertSame('sequence.completed', $delivery->event_type);
        $this->assertSame($sequence->id, $delivery->payload['sequence']['id']);
        $this->assertSame($contact->id, $delivery->payload['contact']['id']);
    }

    public function test_no_delivery_when_no_endpoint_subscribed(): void
    {
        Queue::fake();
        $sequence = Sequence::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $sequence->client_id]);
        WebhookEndpoint::factory()->create([
            'client_id' => $sequence->client_id,
            'events' => ['newsletter.sent'],
        ]);

        app(WebhookEventListener::class)->handle(new ContactSequenceFinished($contact, $sequence));

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());
    }
}
