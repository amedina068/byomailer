<?php

namespace App\Events\Concerns;

use App\Models\Contact;

trait BuildsContactWebhookPayload
{
    public function clientId(): int
    {
        return (int) $this->contact->client_id;
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contactPayload(Contact $contact): array
    {
        return [
            ...$contact->extra_attributes ?? [],
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'email' => $contact->email,
            'name' => $contact->name,
        ];
    }
}
