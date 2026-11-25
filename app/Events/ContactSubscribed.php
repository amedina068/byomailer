<?php

namespace App\Events;

use App\Enums\WebhookEvent;
use App\Events\Concerns\BuildsContactWebhookPayload;
use App\Events\Contracts\WebhookEventContract;
use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactSubscribed implements ShouldDispatchAfterCommit, WebhookEventContract
{
    use BuildsContactWebhookPayload, Dispatchable, SerializesModels;

    public function __construct(public Contact $contact) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::ContactSubscribed;
    }
}
