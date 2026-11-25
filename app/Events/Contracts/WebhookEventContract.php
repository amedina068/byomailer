<?php

namespace App\Events\Contracts;

use App\Enums\WebhookEvent;

interface WebhookEventContract
{
    public function webhookEvent(): WebhookEvent;

    public function clientId(): int;

    /**
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array;
}
