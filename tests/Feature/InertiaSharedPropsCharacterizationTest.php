<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Characterization tests that lock the shape of the props shared by
 * HandleInertiaRequests::share() on any authenticated Inertia page, so a
 * regression during the Laravel 13 + Inertia v3 upgrade is caught.
 */
class InertiaSharedPropsCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_auth_user_is_present_on_an_authenticated_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('auth.user', fn (Assert $authUser) => $authUser
                    ->where('id', $user->id)
                    ->where('email', $user->email)
                    ->etc()
                )
                ->where('auth.timezone', $user->client->timezone ?? 'UTC')
                ->has('name')
                ->has('flash')
                ->where('flash.success', null)
                ->where('flash.error', null)
                ->where('flash.newToken', null)
                ->where('flash.createdTag', null)
                ->where('flash.createdList', null)
                ->missing('flash.planLimitReached')
                ->missing('flash.upgradeRequired')
            );
    }

    public function test_no_billing_props_are_shared(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('hasSubscription')
                ->missing('freeTier')
            );
    }

    public function test_flash_success_is_surfaced_after_a_redirect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['success' => 'Saved.'])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Saved.')
            );
    }

    public function test_flash_error_is_surfaced_after_a_redirect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['error' => 'Add AWS credentials before creating an identity.'])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.error', 'Add AWS credentials before creating an identity.')
                ->where('flash.success', null)
            );
    }
}
