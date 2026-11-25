<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Olive Owner', 'email' => 'owner@example.com']);
    }

    public function test_the_list_shows_every_user_of_every_client(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Mail']);
        User::factory()->create(['client_id' => $client->id, 'name' => 'Ada Lovelace', 'email' => 'ada@acme.test']);
        User::factory()->create(['name' => 'Grace Hopper', 'deactivated_at' => now()]);

        $this->actingAs($this->owner)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Users')
                ->has('users.data', 3)
                ->where('search', '')
                ->where('users.data', fn ($users) => collect($users)->contains(fn ($user) => $user['email'] === 'ada@acme.test'
                    && $user['client_name'] === 'Acme Mail'
                    && $user['is_owner'] === false
                    && $user['deactivated_at'] === null))
                ->where('users.data', fn ($users) => collect($users)->contains(fn ($user) => $user['name'] === 'Grace Hopper'
                    && $user['deactivated_at'] !== null))
                ->where('users.data', fn ($users) => collect($users)->contains(fn ($user) => $user['email'] === 'owner@example.com'
                    && $user['is_owner'] === true
                    && $user['is_current_user'] === true)));
    }

    public function test_the_search_matches_the_name_or_the_email(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@acme.test']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@navy.test']);

        $this->actingAs($this->owner)
            ->get('/admin/users?search=lovelace')
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.email', 'ada@acme.test')
                ->where('search', 'lovelace'));

        $this->actingAs($this->owner)
            ->get('/admin/users?search=navy.test')
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Grace Hopper'));
    }

    public function test_the_search_treats_wildcards_as_text(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($this->owner)
            ->get('/admin/users?search=%25')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
    }

    public function test_the_owner_can_deactivate_and_reactivate_a_user(): void
    {
        $user = User::factory()->create(['email' => 'ada@acme.test']);

        $this->actingAs($this->owner)
            ->from('/admin/users')
            ->post("/admin/users/{$user->id}/deactivation")
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success', 'ada@acme.test is deactivated.');

        $this->assertTrue($user->fresh()->isDeactivated());

        $this->actingAs($this->owner)
            ->from('/admin/users')
            ->delete("/admin/users/{$user->id}/deactivation")
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success', 'ada@acme.test is active again.');

        $this->assertFalse($user->fresh()->isDeactivated());
    }

    public function test_deactivation_keeps_the_data_of_the_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cli');

        $this->actingAs($this->owner)->post("/admin/users/{$user->id}/deactivation");

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('clients', ['id' => $user->client_id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_the_owner_cannot_deactivate_an_owner(): void
    {
        $otherOwner = User::factory()->owner()->create();

        $this->actingAs($this->owner)
            ->post("/admin/users/{$otherOwner->id}/deactivation")
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->post("/admin/users/{$this->owner->id}/deactivation")
            ->assertForbidden();

        $this->assertFalse($otherOwner->fresh()->isDeactivated());
        $this->assertFalse($this->owner->fresh()->isDeactivated());
    }
}
