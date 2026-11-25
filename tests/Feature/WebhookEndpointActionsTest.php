<?php

namespace Tests\Feature;

use App\Actions\CreateWebhookEndpoint;
use App\Actions\DeleteWebhookEndpoint;
use App\Actions\RotateWebhookEndpointSecret;
use App\Actions\UpdateWebhookEndpoint;
use App\Enums\UserRole;
use App\Enums\WebhookDisabledReason;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhookEndpointActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);
    }

    public function test_create_puts_the_endpoint_in_the_client_of_the_user_with_a_new_secret(): void
    {
        $user = User::factory()->create();

        $endpoint = (new CreateWebhookEndpoint)->handle($user, 'https://hooks.example.com/in', ['contact.subscribed'], 'CRM');

        $this->assertTrue($endpoint->exists);
        $this->assertSame($user->client_id, $endpoint->client_id);
        $this->assertSame($user->id, $endpoint->user_id);
        $this->assertSame('https://hooks.example.com/in', $endpoint->url);
        $this->assertSame(['contact.subscribed'], $endpoint->events);
        $this->assertSame('CRM', $endpoint->description);
        $this->assertStringStartsWith('whsec_', $endpoint->secret);
    }

    public function test_update_changes_only_the_given_fields(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['description' => 'Keep']);

        (new UpdateWebhookEndpoint)->handle($endpoint, ['active' => false]);

        $endpoint->refresh();
        $this->assertFalse($endpoint->active);
        $this->assertSame(WebhookDisabledReason::Manual, $endpoint->disabled_reason);
        $this->assertSame('Keep', $endpoint->description);

        (new UpdateWebhookEndpoint)->handle($endpoint, ['description' => null]);

        $this->assertNull($endpoint->fresh()->description);
        $this->assertFalse($endpoint->fresh()->active);
    }

    public function test_update_enables_an_endpoint_and_resets_the_failures(): void
    {
        $endpoint = WebhookEndpoint::factory()->autoDisabled()->create();

        (new UpdateWebhookEndpoint)->handle($endpoint, ['active' => true]);

        $endpoint->refresh();
        $this->assertTrue($endpoint->active);
        $this->assertSame(0, $endpoint->consecutive_failures);
        $this->assertNull($endpoint->disabled_at);
        $this->assertNull($endpoint->disabled_reason);
    }

    public function test_update_to_the_current_status_changes_nothing(): void
    {
        $paused = WebhookEndpoint::factory()->autoDisabled()->create(['disabled_at' => now()->subDay()]);
        $active = WebhookEndpoint::factory()->create(['consecutive_failures' => 3]);
        $pausedAt = $paused->disabled_at->toDateTimeString();

        (new UpdateWebhookEndpoint)->handle($paused, ['active' => false]);
        (new UpdateWebhookEndpoint)->handle($active, ['active' => true]);

        $this->assertSame(WebhookDisabledReason::AutoFailures, $paused->fresh()->disabled_reason);
        $this->assertSame($pausedAt, $paused->fresh()->disabled_at->toDateTimeString());
        $this->assertSame(3, $active->fresh()->consecutive_failures);
    }

    public function test_rotate_secret_saves_a_new_secret(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'whsec_old']);

        $rotated = (new RotateWebhookEndpointSecret)->handle($endpoint);

        $this->assertNotSame('whsec_old', $rotated->secret);
        $this->assertSame($rotated->secret, $endpoint->fresh()->secret);
    }

    public function test_delete_removes_the_endpoint(): void
    {
        $endpoint = WebhookEndpoint::factory()->create();

        (new DeleteWebhookEndpoint)->handle($endpoint);

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_web_disable_keeps_the_reason_of_a_paused_endpoint(): void
    {
        $user = User::factory()->create();
        $endpoint = WebhookEndpoint::factory()->autoDisabled()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patch(route('webhooks.status', $endpoint->id), ['active' => false])->assertRedirect();

        $this->assertSame(WebhookDisabledReason::AutoFailures, $endpoint->fresh()->disabled_reason);
    }

    public function test_web_writes_go_through_the_actions(): void
    {
        $user = User::factory()->create();
        $this->expectEachActionOnce();

        $this->actingAs($user)->post(route('webhooks.store'), ['url' => 'https://web.example.com/in', 'events' => ['contact.subscribed']])->assertRedirect();
        $endpoint = WebhookEndpoint::query()->sole();
        $this->actingAs($user)->patch(route('webhooks.status', $endpoint->id), ['active' => false])->assertRedirect();
        $this->actingAs($user)->post(route('webhooks.secret', $endpoint->id))->assertRedirect();
        $this->actingAs($user)->delete(route('webhooks.destroy', $endpoint->id))->assertRedirect();

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_api_writes_go_through_the_actions(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->expectEachActionOnce();

        $endpointId = $this->postJson('/api/v1/webhook-endpoints', ['url' => 'https://api.example.com/in', 'events' => ['contact.subscribed']])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/webhook-endpoints/{$endpointId}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->postJson("/api/v1/webhook-endpoints/{$endpointId}/secret")->assertOk();
        $this->deleteJson("/api/v1/webhook-endpoints/{$endpointId}")->assertNoContent();

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpointId]);
    }

    public function test_both_channels_keep_the_admin_check_before_the_actions(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
        $endpoint = WebhookEndpoint::factory()->create(['user_id' => $viewer->id]);
        foreach ([CreateWebhookEndpoint::class, UpdateWebhookEndpoint::class, RotateWebhookEndpointSecret::class, DeleteWebhookEndpoint::class] as $action) {
            $this->mock($action)->shouldNotReceive('handle');
        }
        $payload = ['url' => 'https://ok.example.com/in', 'events' => ['contact.subscribed']];

        $this->actingAs($viewer)->post(route('webhooks.store'), $payload)->assertForbidden();
        $this->actingAs($viewer)->patch(route('webhooks.status', $endpoint->id), ['active' => false])->assertForbidden();
        $this->actingAs($viewer)->post(route('webhooks.secret', $endpoint->id))->assertForbidden();
        $this->actingAs($viewer)->delete(route('webhooks.destroy', $endpoint->id))->assertForbidden();

        Sanctum::actingAs($viewer, ['*']);
        $this->postJson('/api/v1/webhook-endpoints', $payload)->assertForbidden();
        $this->patchJson("/api/v1/webhook-endpoints/{$endpoint->id}", ['active' => false])->assertForbidden();
        $this->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/secret")->assertForbidden();
        $this->deleteJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertForbidden();

        $this->assertTrue($endpoint->fresh()->active);
    }

    private function expectEachActionOnce(): void
    {
        foreach ([CreateWebhookEndpoint::class, UpdateWebhookEndpoint::class, RotateWebhookEndpointSecret::class, DeleteWebhookEndpoint::class] as $action) {
            $this->partialMock($action)->shouldReceive('handle')->once()->passthru();
        }
    }
}
