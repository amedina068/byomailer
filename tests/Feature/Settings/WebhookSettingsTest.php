<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebhookSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_index_renders_with_endpoints(): void
    {
        $user = $this->admin();
        WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->get('/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/webhooks/Index')
                ->has('endpoints', 1)
                ->where('disabledReasons.AutoFailures', 'auto_failures')
                ->where('newSecret', null));
    }

    public function test_store_flashes_secret_once(): void
    {
        $this->admin();

        $this->followingRedirects()->post('/settings/webhooks', [
            'url' => 'https://hooks.example.com/in',
            'events' => ['contact.subscribed'],
        ])->assertInertia(fn (Assert $page) => $page
            ->component('settings/webhooks/Index')
            ->where('newSecret', fn ($s) => ! empty($s)));

        // Next visit must not expose the secret.
        $this->get('/settings/webhooks')
            ->assertInertia(fn (Assert $page) => $page->where('newSecret', null));

        $this->assertDatabaseCount('webhook_endpoints', 1);
    }

    public function test_show_renders(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->autoDisabled()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->get("/settings/webhooks/{$endpoint->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/webhooks/Show')
                ->where('endpoint.id', $endpoint->id)
                ->where('endpoint.disabled_reason', 'auto_failures')
                ->where('disabledReasons', ['Manual' => 'manual', 'AutoFailures' => 'auto_failures']));
    }

    public function test_status_toggle_resets_failures_on_reenable(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->autoDisabled()->create([
            'client_id' => $user->client_id, 'user_id' => $user->id,
        ]);

        $this->patch("/settings/webhooks/{$endpoint->id}/status", ['active' => true])->assertRedirect();

        $endpoint->refresh();
        $this->assertTrue($endpoint->active);
        $this->assertSame(0, $endpoint->consecutive_failures);
        $this->assertNull($endpoint->disabled_at);
        $this->assertNull($endpoint->disabled_reason);
    }

    public function test_regenerate_secret_flashes_new_value(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create([
            'client_id' => $user->client_id, 'user_id' => $user->id, 'secret' => 'whsec_old',
        ]);

        $this->followingRedirects()->post("/settings/webhooks/{$endpoint->id}/secret")
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/webhooks/Show')
                ->where('newSecret', fn ($s) => ! empty($s) && $s !== 'whsec_old'));

        $this->assertNotSame('whsec_old', $endpoint->fresh()->secret);
    }

    public function test_destroy(): void
    {
        $user = $this->admin();
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->delete("/settings/webhooks/{$endpoint->id}")->assertRedirect();
        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create(['role' => UserRole::Viewer->value]);
        $this->actingAs($user);
        $endpoint = WebhookEndpoint::factory()->create(['client_id' => $user->client_id, 'user_id' => $user->id]);

        $this->get('/settings/webhooks')->assertForbidden();
        $this->post('/settings/webhooks', ['url' => 'https://ok.example.com', 'events' => ['contact.subscribed']])->assertForbidden();
        $this->get("/settings/webhooks/{$endpoint->id}")->assertForbidden();
        $this->patch("/settings/webhooks/{$endpoint->id}/status", ['active' => false])->assertForbidden();
        $this->post("/settings/webhooks/{$endpoint->id}/secret")->assertForbidden();
        $this->delete("/settings/webhooks/{$endpoint->id}")->assertForbidden();
    }

    public function test_test_route_is_rate_limited(): void
    {
        $middleware = Route::getRoutes()->getByName('webhooks.test')->gatherMiddleware();

        $this->assertContains('throttle:webhook-test-ping', $middleware);
    }
}
