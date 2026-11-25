<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookEvent;
use App\Events\ContactResubscribed;
use App\Events\ContactSequenceFinished;
use App\Events\ContactSubscribed;
use App\Events\ContactUnsubscribed;
use App\Events\Contracts\WebhookEventContract;
use App\Events\NewsletterSent;
use App\Events\SequenceStepSent;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEventPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_events_expose_contract(): void
    {
        $contact = Contact::factory()->create(['extra_attributes' => ['company' => 'Acme', 'plan' => 'pro']]);

        foreach ([ContactSubscribed::class, ContactUnsubscribed::class, ContactResubscribed::class] as $class) {
            $event = new $class($contact);

            $this->assertInstanceOf(WebhookEventContract::class, $event);
            $this->assertSame((int) $contact->client_id, $event->clientId());
            $payload = $event->toWebhookPayload();
            $this->assertSame($event->webhookEvent()->value, $payload['event']);
            $this->assertSame($contact->id, $payload['contact']['id']);
            $this->assertSame($contact->email, $payload['contact']['email']);
            $this->assertSame('Acme', $payload['contact']['company']);
            $this->assertSame('pro', $payload['contact']['plan']);
            $this->assertArrayNotHasKey('extra_attributes', $payload['contact']);
        }
    }

    public function test_newsletter_sent_payload(): void
    {
        $newsletter = Newsletter::factory()->create();

        $event = new NewsletterSent($newsletter);

        $this->assertSame(WebhookEvent::NewsletterSent, $event->webhookEvent());
        $this->assertSame($newsletter->client_id, $event->clientId());
        $this->assertSame($newsletter->id, $event->toWebhookPayload()['newsletter']['id']);
    }

    public function test_sequence_step_sent_payload(): void
    {
        $sequence = Sequence::factory()->create();
        $step = SequenceStep::factory()->create(['sequence_id' => $sequence->id]);
        $contact = Contact::factory()->create();
        $this->actingAsTenant($sequence->client_id);

        $event = new SequenceStepSent($step, $contact);

        $this->assertSame(WebhookEvent::SequenceStepSent, $event->webhookEvent());
        $payload = $event->toWebhookPayload();
        $this->assertSame($step->id, $payload['sequence_step']['id']);
        $this->assertSame($contact->id, $payload['contact']['id']);
    }

    public function test_sequence_completed_reuses_existing_event(): void
    {
        $sequence = Sequence::factory()->create();
        $contact = Contact::factory()->create();

        $event = new ContactSequenceFinished($contact, $sequence);

        $this->assertInstanceOf(WebhookEventContract::class, $event);
        $this->assertSame(WebhookEvent::SequenceCompleted, $event->webhookEvent());
        $this->assertSame($contact->client_id, $event->clientId());
        $this->assertSame($sequence->id, $event->toWebhookPayload()['sequence']['id']);
    }
}
