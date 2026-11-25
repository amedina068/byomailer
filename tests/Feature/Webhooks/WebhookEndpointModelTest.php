<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookDisabledReason;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookEndpointModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_encrypted_at_rest(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'plain-secret-value']);

        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertNotSame('plain-secret-value', $raw);
        $this->assertSame('plain-secret-value', $endpoint->fresh()->secret);
    }

    public function test_secret_is_generated_when_absent(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => null]);

        $this->assertNotEmpty($endpoint->secret);
        $this->assertGreaterThanOrEqual(40, strlen($endpoint->secret));
    }

    public function test_events_cast_to_array(): void
    {
        $endpoint = WebhookEndpoint::factory()->create([
            'events' => ['contact.subscribed', 'newsletter.sent'],
        ]);

        $this->assertSame(['contact.subscribed', 'newsletter.sent'], $endpoint->fresh()->events);
    }

    public function test_subscribes_to_reflects_events_array(): void
    {
        $endpoint = WebhookEndpoint::factory()->create([
            'events' => ['contact.subscribed', 'newsletter.sent'],
        ]);

        $this->assertTrue($endpoint->subscribesTo('contact.subscribed'));
        $this->assertTrue($endpoint->subscribesTo('newsletter.sent'));
        $this->assertFalse($endpoint->subscribesTo('contact.unsubscribed'));
    }

    public function test_disabled_reason_is_cast_to_the_enum(): void
    {
        $endpoint = WebhookEndpoint::factory()->create();

        $endpoint->deactivate(WebhookDisabledReason::AutoFailures);

        $this->assertSame('auto_failures', DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('disabled_reason'));
        $this->assertSame(WebhookDisabledReason::AutoFailures, $endpoint->fresh()->disabled_reason);

        $endpoint->activate();

        $this->assertNull($endpoint->fresh()->disabled_reason);
    }

    public function test_delivery_status_is_cast_to_the_enum(): void
    {
        $delivery = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Failed]);

        $this->assertSame('failed', DB::table('webhook_deliveries')->where('id', $delivery->id)->value('status'));
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->fresh()->status);
    }

    public function test_factory_states(): void
    {
        $this->assertFalse(WebhookEndpoint::factory()->inactive()->create()->active);

        $autoDisabled = WebhookEndpoint::factory()->autoDisabled()->create();
        $this->assertFalse($autoDisabled->active);
        $this->assertSame(WebhookDisabledReason::AutoFailures, $autoDisabled->disabled_reason);
        $this->assertNotNull($autoDisabled->disabled_at);
    }
}
