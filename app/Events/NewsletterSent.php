<?php

namespace App\Events;

use App\Enums\WebhookEvent;
use App\Events\Contracts\WebhookEventContract;
use App\Models\Newsletter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsletterSent implements WebhookEventContract
{
    use Dispatchable, SerializesModels;

    public function __construct(public Newsletter $newsletter) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::NewsletterSent;
    }

    public function clientId(): int
    {
        return (int) $this->newsletter->client_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array
    {
        return [
            'event' => $this->webhookEvent()->value,
            'occurred_at' => now()->toIso8601String(),
            'newsletter' => [
                'id' => $this->newsletter->id,
                'uuid' => $this->newsletter->uuid,
                'title' => $this->newsletter->title,
                'sent_at' => optional($this->newsletter->sent_at)->toIso8601String(),
            ],
        ];
    }
}
