<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Only the owner can open the admin area. Everyone else gets a 404, so the
 * area does not show that it exists.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function adminRoutes(User $target): array
    {
        return [
            ['get', '/admin'],
            ['get', '/admin/users'],
            ['get', '/admin/settings'],
            ['put', '/admin/settings'],
            ['post', "/admin/users/{$target->id}/deactivation"],
            ['delete', "/admin/users/{$target->id}/deactivation"],
        ];
    }

    public function test_a_guest_gets_404_on_every_admin_route(): void
    {
        $target = User::factory()->create();

        foreach ($this->adminRoutes($target) as [$method, $uri]) {
            $this->{$method}($uri)->assertNotFound();
        }
    }

    public function test_a_team_admin_that_is_not_the_owner_gets_404(): void
    {
        $teamAdmin = User::factory()->create(['is_owner' => false]);
        $target = User::factory()->create();

        foreach ($this->adminRoutes($target) as [$method, $uri]) {
            $this->actingAs($teamAdmin)->{$method}($uri)->assertNotFound();
        }

        $this->assertNull($target->fresh()->deactivated_at);
    }

    public function test_the_owner_can_open_the_admin_area(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/admin')->assertRedirect('/admin/users');
        $this->actingAs($owner)->get('/admin/users')->assertOk();
        $this->actingAs($owner)->get('/admin/settings')->assertOk();
    }

    public function test_only_the_owner_gets_the_admin_link_in_the_sidebar(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->assertContains('admin', $this->navigationKeys($owner));
        $this->assertNotContains('admin', $this->navigationKeys($member));
        $this->assertNotContains('admin', $this->navigationKeys(null));
    }

    /**
     * @return list<string>
     */
    private function navigationKeys(?User $user): array
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $user);

        $shared = app(HandleInertiaRequests::class)->share($request);

        return array_column($shared['navigation']['main'], 'key');
    }
}
