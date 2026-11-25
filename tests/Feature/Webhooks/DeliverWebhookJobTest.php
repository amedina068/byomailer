<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookDisabledReason;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class DeliverWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind an SsrfGuard that resolves any host to a public IP (no real DNS in tests).
        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);
    }

    private function makeDelivery(array $endpointAttrs = []): WebhookDelivery
    {
        $endpoint = WebhookEndpoint::factory()->create(array_merge([
            'url' => 'https://hooks.example.com/in',
            'secret' => 'whsec_known',
            'events' => ['contact.subscribed'],
        ], $endpointAttrs));

        return WebhookDelivery::factory()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'client_id' => $endpoint->client_id,
            'event_type' => 'contact.subscribed',
            'payload' => ['event' => 'contact.subscribed', 'contact' => ['id' => 1]],
        ]);
    }

    private function runJob(WebhookDelivery $delivery): void
    {
        (new DeliverWebhookJob($delivery->webhook_endpoint_id, $delivery->id))
            ->handle(app(SsrfGuard::class));
    }

    public function test_successful_delivery_marks_success_and_resets_failures(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->makeDelivery();
        WebhookEndpoint::withoutGlobalScopes()->whereKey($delivery->webhook_endpoint_id)
            ->update(['consecutive_failures' => 3]);

        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Success, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($delivery->webhook_endpoint_id);
        $this->assertSame(0, $endpoint->consecutive_failures);
        $this->assertNotNull($endpoint->last_triggered_at);
    }

    public function test_sends_signed_headers(): void
    {
        $this->travelTo(now());
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->makeDelivery();
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($delivery->webhook_endpoint_id);

        $this->runJob($delivery);

        Http::assertSent(function (Request $request) use ($endpoint, $delivery) {
            $timestamp = $request->header('X-Byomailer-Timestamp')[0];
            $expected = WebhookSignature::sign($timestamp, $request->body(), $endpoint->secret);

            return $request->hasHeader('X-Byomailer-Signature', $expected)
                && $request->hasHeader('X-Byomailer-Event', 'contact.subscribed')
                && $request->hasHeader('X-Byomailer-Delivery', $delivery->uuid)
                && $request->hasHeader('User-Agent', 'BYOMailer-Webhook/1.0');
        });
    }

    public function test_does_not_follow_redirects(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/'])]);

        $delivery = $this->makeDelivery();

        try {
            $this->runJob($delivery);
        } catch (RequestException) {
            // non-2xx throws to trigger retry
        }

        $delivery->refresh();
        $this->assertSame(301, $delivery->response_status);
        $this->assertNotSame(WebhookDeliveryStatus::Success, $delivery->status);
    }

    public function test_aborts_when_delivery_time_ssrf_check_fails(): void
    {
        Http::fake();

        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => '10.0.0.5');
        $this->app->instance(SsrfGuard::class, $guard);

        $delivery = $this->makeDelivery();
        $this->runJob($delivery);

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
    }

    public function test_truncates_response_body(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 10000), 200)]);

        $delivery = $this->makeDelivery();
        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertLessThanOrEqual(config('webhooks.response_body_cap_bytes'), strlen((string) $delivery->response_body));
    }

    public function test_rate_limited_marks_failed_without_sending(): void
    {
        Http::fake();
        config(['webhooks.delivery_rate_per_minute' => 1]);

        $delivery = $this->makeDelivery();
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($delivery->webhook_endpoint_id);

        // Saturate the per-client limiter.
        RateLimiter::hit('webhook-delivery:'.$endpoint->client_id, 60);

        $this->runJob($delivery);

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('rate_limited', (string) $delivery->error);
    }

    public function test_failed_callback_increments_and_auto_disables(): void
    {
        config(['webhooks.disable_after_failures' => 3]);

        $delivery = $this->makeDelivery();
        $endpointId = $delivery->webhook_endpoint_id;
        WebhookEndpoint::withoutGlobalScopes()->whereKey($endpointId)->update(['consecutive_failures' => 2]);

        (new DeliverWebhookJob($endpointId, $delivery->id))->failed(new RuntimeException('boom'));

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($endpointId);
        $this->assertSame(3, $endpoint->consecutive_failures);
        $this->assertFalse($endpoint->active);
        $this->assertSame(WebhookDisabledReason::AutoFailures, $endpoint->disabled_reason);

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
    }

    public function test_failed_callback_below_threshold_does_not_disable(): void
    {
        config(['webhooks.disable_after_failures' => 3]);

        $delivery = $this->makeDelivery();
        $endpointId = $delivery->webhook_endpoint_id;
        WebhookEndpoint::withoutGlobalScopes()->whereKey($endpointId)->update(['consecutive_failures' => 1]);

        (new DeliverWebhookJob($endpointId, $delivery->id))->failed(new RuntimeException('boom'));

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($endpointId);
        $this->assertSame(2, $endpoint->consecutive_failures);
        $this->assertTrue($endpoint->active);
        $this->assertNull($endpoint->disabled_reason);
    }

    public function test_job_dispatches_after_commit(): void
    {
        $this->assertTrue((new DeliverWebhookJob(1, 1))->afterCommit);
    }
}
