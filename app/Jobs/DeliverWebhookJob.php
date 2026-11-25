<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookDisabledReason;
use App\Exceptions\SsrfException;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 3600, 7200];

    public int $timeout = 30;

    public function __construct(
        public int $webhookEndpointId,
        public int $webhookDeliveryId,
    ) {
        $this->onQueue('webhooks');
        $this->afterCommit = true;
    }

    public function handle(SsrfGuard $ssrfGuard): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($this->webhookEndpointId);
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->webhookDeliveryId);

        if (! $endpoint || ! $delivery) {
            return;
        }

        // Per-client delivery rate limit; mark rate_limited without retry when exhausted.
        $rateKey = 'webhook-delivery:'.$endpoint->client_id;

        if (RateLimiter::tooManyAttempts($rateKey, (int) config('webhooks.delivery_rate_per_minute'))) {
            $this->recordInlineFailure($delivery, 'rate_limited: per-client delivery rate limit exceeded');

            return;
        }

        RateLimiter::hit($rateKey, 60);

        // Delivery-time SSRF recheck (DNS rebinding); abort without sending, no retry.
        try {
            $ip = $ssrfGuard->assertSafe($endpoint->url);
        } catch (SsrfException $e) {
            $this->recordInlineFailure($delivery, 'Blocked by SSRF guard: '.$e->getMessage());

            return;
        }

        $host = (string) parse_url($endpoint->url, PHP_URL_HOST);
        $port = (int) (parse_url($endpoint->url, PHP_URL_PORT) ?: 443);

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $response = Http::withoutRedirecting()
            ->timeout((int) config('webhooks.timeout_seconds'))
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
            ->withHeaders([
                'User-Agent' => 'BYOMailer-Webhook/1.0',
                'Content-Type' => 'application/json',
                'X-Byomailer-Event' => $delivery->event_type,
                'X-Byomailer-Delivery' => $delivery->uuid,
                'X-Byomailer-Timestamp' => $timestamp,
                WebhookSignature::HEADER => WebhookSignature::sign($timestamp, $body, $endpoint->secret),
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);

        $delivery->forceFill([
            'attempts' => $this->attempts(),
            'response_status' => $response->status(),
            'response_body' => $this->truncate($response->body()),
        ])->save();

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Success,
                'delivered_at' => now(),
                'error' => null,
            ])->save();

            WebhookEndpoint::withoutGlobalScopes()
                ->whereKey($endpoint->id)
                ->update(['consecutive_failures' => 0, 'last_triggered_at' => now()]);

            return;
        }

        // Non-2xx: throw so the queue retries; failed() records the terminal failure.
        $response->throw();
    }

    public function failed(?Throwable $exception): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($this->webhookEndpointId);
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->webhookDeliveryId);

        if ($delivery) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Failed,
                'error' => $exception?->getMessage(),
            ])->save();
        }

        if (! $endpoint) {
            return;
        }

        // Atomic increment to avoid a lost-update race between concurrent failures.
        WebhookEndpoint::withoutGlobalScopes()->whereKey($endpoint->id)->increment('consecutive_failures');

        $failures = (int) WebhookEndpoint::withoutGlobalScopes()->whereKey($endpoint->id)->value('consecutive_failures');

        if ($failures >= (int) config('webhooks.disable_after_failures')) {
            WebhookEndpoint::withoutGlobalScopes()
                ->whereKey($endpoint->id)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'disabled_at' => now(),
                    'disabled_reason' => WebhookDisabledReason::AutoFailures,
                ]);
        }
    }

    private function recordInlineFailure(WebhookDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Failed,
            'attempts' => $this->attempts(),
            'error' => $error,
        ])->save();
    }

    private function truncate(string $body): string
    {
        return mb_strcut($body, 0, (int) config('webhooks.response_body_cap_bytes'));
    }
}
