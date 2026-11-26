<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\LoadsHorizon;
use Tests\TestCase;

/**
 * Only the owner of the instance opens Horizon. This is true in every
 * environment, also in `local`, because a self-hosted install can run with
 * APP_ENV=local by mistake.
 */
class HorizonGateTest extends TestCase
{
    use LoadsHorizon, RefreshDatabase;

    public function test_a_listed_admin_email_that_is_not_the_owner_cannot_view_horizon(): void
    {
        config(['app.admin_emails' => ['admin@example.com']]);
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_a_user_that_is_not_the_owner_cannot_view_horizon(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_the_owner_can_view_horizon(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->assertTrue(Gate::forUser($owner)->allows('viewHorizon'));
    }

    public function test_an_owner_without_a_verified_email_cannot_view_horizon(): void
    {
        $owner = User::factory()->unverified()->create(['is_owner' => true]);

        $this->assertFalse(Gate::forUser($owner)->allows('viewHorizon'));
    }

    public function test_a_guest_and_a_user_cannot_open_horizon_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    }

    public function test_the_owner_can_open_horizon_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs(User::factory()->create(['is_owner' => true]))->get('/horizon')->assertOk();
    }

    public function test_env_example_runs_in_production_mode_without_debug(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
    }
}
