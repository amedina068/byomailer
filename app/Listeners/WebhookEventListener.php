<?php

namespace App\Listeners;

use App\Events\Contracts\WebhookEventContract;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class WebhookEventListener implements ShouldQueue
{
    public string $queue = 'webhooks';

    public function __construct(private WebhookDispatcher $dispatcher) {}

    public function handle(WebhookEventContract $event): void
    {
        $this->dispatcher->dispatch(
            $event->webhookEvent(),
            $event->clientId(),
            $event->toWebhookPayload(),
        );
    }
}
