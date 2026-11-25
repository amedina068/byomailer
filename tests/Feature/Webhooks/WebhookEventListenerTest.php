<?php

namespace Tests\Feature\Webhooks;

use App\Events\ContactSequenceFinished;
use App\Events\ContactSubscribed;
use App\Events\NewsletterSent;
use App\Jobs\DeliverWebhookJob;
use App\Listeners\AdvanceVisualAutomationOnSequenceFinished;
use App\Listeners\RunAutomationsForFinishedSequence;
use App\Listeners\WebhookEventListener;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEventListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new WebhookEventListener(app(WebhookDispatcher::class)));
    }

    public function test_handles_contract_event_and_queues_delivery(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create(['events' => ['contact.subscribed']]);
        $contact = Contact::factory()->create(['client_id' => $endpoint->client_id]);

        app(WebhookEventListener::class)->handle(new ContactSubscribed($contact));

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_does_not_queue_when_no_matching_endpoint(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create(['events' => ['contact.subscribed']]);
        $newsletter = Newsletter::factory()->create(['client_id' => $endpoint->client_id]);

        app(WebhookEventListener::class)->handle(new NewsletterSent($newsletter));

        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_sequence_finished_handled_via_listener(): void
    {
        Queue::fake();
        $sequence = Sequence::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $sequence->client_id]);
        WebhookEndpoint::factory()->create([
            'client_id' => $sequence->client_id,
            'events' => ['sequence.completed'],
        ]);

        app(WebhookEventListener::class)->handle(new ContactSequenceFinished($contact, $sequence));

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_event_wiring_registers_all_listeners(): void
    {
        Event::fake();

        Event::assertListening(ContactSubscribed::class, WebhookEventListener::class);
        Event::assertListening(NewsletterSent::class, WebhookEventListener::class);
        Event::assertListening(ContactSequenceFinished::class, WebhookEventListener::class);
        // Existing sequence-finished listeners must still be wired (regression).
        Event::assertListening(ContactSequenceFinished::class, RunAutomationsForFinishedSequence::class);
        Event::assertListening(ContactSequenceFinished::class, AdvanceVisualAutomationOnSequenceFinished::class);
    }
}
