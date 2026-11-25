<?php

namespace App\Events;

use App\Enums\WebhookEvent;
use App\Events\Concerns\BuildsContactWebhookPayload;
use App\Events\Contracts\WebhookEventContract;
use App\Models\Contact;
use App\Models\Sequence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduler can dispatch this event inside a transaction, for example
 * when a skipped last step ends the sequence. The queued listeners must not
 * run before that transaction commits, and must not run if it rolls back.
 */
class ContactSequenceFinished implements ShouldDispatchAfterCommit, WebhookEventContract
{
    use BuildsContactWebhookPayload, Dispatchable, SerializesModels;

    public function __construct(
        public Contact $contact,
        public Sequence $sequence,
    ) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::SequenceCompleted;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array
    {
        return [
            'event' => $this->webhookEvent()->value,
            'occurred_at' => now()->toIso8601String(),
            'contact' => self::contactPayload($this->contact),
            'sequence' => [
                'id' => $this->sequence->id,
                'name' => $this->sequence->name,
            ],
        ];
    }
}
