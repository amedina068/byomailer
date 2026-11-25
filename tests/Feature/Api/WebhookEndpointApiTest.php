<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhookEndpointApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve any hostname to a public IP so fake test domains pass the SSRF
        // guard; http:// and private IP literals are still rejected pre-DNS.
        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['role' => UserRole::Viewer->value]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_admin_can_create_endpoint_and_secret_returned_once(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://hooks.example.com/in',
            'events' => ['contact.subscribed', 'newsletter.sent'],
            'description' => 'CRM sync',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.url', 'https://hooks.example.com/in')
            ->assertJsonPath('data.events', ['contact.subscribed', 'newsletter.sent']);

        $this->assertNotEmpty($response->json('data.secret'));
    }

    public function test_secret_is_not_exposed_on_index_show_update(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->getJson('/api/v1/webhook-endpoints')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->getJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertOk()->assertJsonMissingPath('data.secret');
        $this->patchJson("/api/v1/webhook-endpoints/{$endpoint->id}", ['description' => 'x'])
            ->assertOk()->assertJsonMissingPath('data.secret');
    }

    public function test_validation_rejects_bad_url_and_empty_events(): void
    {
        $this->admin();

        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'http://example.com', 'events' => ['contact.subscribed']])
            ->assertStatus(422)->assertJsonValidationErrorFor('url');
        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://127.0.0.1', 'events' => ['contact.subscribed']])
            ->assertStatus(422)->assertJsonValidationErrorFor('url');
        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://ok.example.com', 'events' => []])
            ->assertStatus(422)->assertJsonValidationErrorFor('events');
        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://ok.example.com', 'events' => ['nope.bad']])
            ->assertStatus(422)->assertJsonValidationErrorFor('events.0');
    }

    public function test_endpoint_cap_enforced(): void
    {
        $user = $this->admin();
        config(['webhooks.max_per_client' => 2]);
        WebhookEndpoint::factory()->count(2)->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://ok.example.com', 'events' => ['contact.subscribed']])
            ->assertStatus(422)->assertJsonValidationErrorFor('endpoints');
    }

    public function test_update_only_touches_active_and_description(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'url' => 'https://keep.example.com/in', 'events' => ['contact.subscribed']]);

        $this->patchJson("/api/v1/webhook-endpoints/{$endpoint->id}", [
            'url' => 'https://changed.example.com',
            'events' => ['newsletter.sent'],
            'active' => false,
            'description' => 'updated',
        ])->assertOk();

        $endpoint->refresh();
        $this->assertSame('https://keep.example.com/in', $endpoint->url);
        $this->assertSame(['contact.subscribed'], $endpoint->events);
        $this->assertFalse($endpoint->active);
        $this->assertSame('updated', $endpoint->description);
    }

    public function test_destroy(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->deleteJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertNoContent();
        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_non_admin_forbidden_on_all_actions(): void
    {
        $user = $this->viewer();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->getJson('/api/v1/webhook-endpoints')->assertForbidden();
        $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://ok.example.com', 'events' => ['contact.subscribed']])->assertForbidden();
        $this->getJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertForbidden();
        $this->patchJson("/api/v1/webhook-endpoints/{$endpoint->id}", ['description' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertForbidden();
        $this->getJson("/api/v1/webhook-endpoints/{$endpoint->id}/deliveries")->assertForbidden();
        $this->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/test")->assertForbidden();
        $this->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/secret")->assertForbidden();
    }

    public function test_cross_tenant_show_returns_404(): void
    {
        $this->admin();
        $other = WebhookEndpoint::factory()->create();

        $this->getJson("/api/v1/webhook-endpoints/{$other->id}")->assertNotFound();
    }

    public function test_deliveries_index_scoped_to_endpoint(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);
        WebhookDelivery::factory()->count(3)->create(['webhook_endpoint_id' => $endpoint->id, 'client_id' => $user->client_id]);

        $this->getJson("/api/v1/webhook-endpoints/{$endpoint->id}/deliveries")
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_test_ping_queues_delivery(): void
    {
        Queue::fake();
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'events' => ['contact.subscribed']]);

        $this->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/test")->assertAccepted();

        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->count());
    }

    public function test_regenerate_secret_rotates_and_returns_new_value(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id, 'secret' => 'whsec_old']);

        $response = $this->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/secret")->assertOk();

        $newSecret = $response->json('data.secret');
        $this->assertNotEmpty($newSecret);
        $this->assertNotSame('whsec_old', $newSecret);
        $this->assertSame($newSecret, $endpoint->fresh()->secret);
    }
}
