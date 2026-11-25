<?php

namespace App\Events;

use App\Enums\WebhookEvent;
use App\Events\Concerns\BuildsContactWebhookPayload;
use App\Events\Contracts\WebhookEventContract;
use App\Models\Contact;
use App\Models\SequenceStep;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SequenceStepSent implements WebhookEventContract
{
    use BuildsContactWebhookPayload, Dispatchable, SerializesModels;

    public function __construct(
        public SequenceStep $sequenceStep,
        public Contact $contact,
    ) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::SequenceStepSent;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array
    {
        $sequence = $this->sequenceStep->sequence;

        return [
            'event' => $this->webhookEvent()->value,
            'occurred_at' => now()->toIso8601String(),
            'contact' => self::contactPayload($this->contact),
            'sequence' => [
                'id' => $sequence?->id,
                'name' => $sequence?->name,
            ],
            'sequence_step' => [
                'id' => $this->sequenceStep->id,
            ],
        ];
    }
}
