<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileInstanceOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_page_tells_the_instance_owner_that_the_account_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Profile')
                ->where('isInstanceOwner', true));
    }

    public function test_the_profile_page_lets_another_user_delete_the_account(): void
    {
        $user = User::factory()->create(['is_owner' => false]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isInstanceOwner', false));
    }
}
