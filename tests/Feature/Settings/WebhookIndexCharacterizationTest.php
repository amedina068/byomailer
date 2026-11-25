<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Characterization tests locking the webhook settings index component + prop
 * contract for the unconfigured vs configured states ahead of the Laravel 13 /
 * Inertia v3 upgrade.
 */
class WebhookIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_unconfigured_empty_state_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/webhooks/Index')
                ->has('endpoints', 0)
                ->where('newSecret', null)
                ->has('webhookEvents', 6, fn (Assert $event) => $event
                    ->has('value')
                    ->has('group')
                    ->has('description')
                )
            );
    }

    public function test_index_configured_state_shape(): void
    {
        $user = User::factory()->create();
        $endpoint = WebhookEndpoint::factory()->create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/webhooks/Index')
                ->has('endpoints', 1, fn (Assert $prop) => $prop
                    ->where('id', $endpoint->id)
                    ->etc()
                )
                ->where('newSecret', null)
                ->has('webhookEvents')
            );
    }
}
