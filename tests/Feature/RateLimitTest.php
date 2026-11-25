<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_limits_the_requests_of_each_user(): void
    {
        config(['app.api_rate_limit' => 2]);

        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson(route('api.v1.tags.index'))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.tags.index'))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.tags.index'))->assertTooManyRequests();
    }

    public function test_the_api_limit_of_one_user_does_not_affect_another_user(): void
    {
        config(['app.api_rate_limit' => 1]);

        $first = User::factory()->create()->createToken('first')->plainTextToken;
        $second = User::factory()->create()->createToken('second')->plainTextToken;

        $this->withToken($first)->getJson(route('api.v1.tags.index'))->assertOk();
        $this->withToken($first)->getJson(route('api.v1.tags.index'))->assertTooManyRequests();

        $this->app['auth']->forgetGuards();

        $this->withToken($second)->getJson(route('api.v1.tags.index'))->assertOk();
    }

    public function test_each_api_route_uses_the_api_limit(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'api.v1.'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains('throttle:api', $route->gatherMiddleware(), "The route {$route->getName()} has no API rate limit.");
        }
    }

    public function test_the_dashboard_test_mail_routes_are_rate_limited(): void
    {
        foreach (['newsletters.test-mail', 'sequences.steps.test-mail'] as $name) {
            $this->assertContains(
                'throttle:newsletter-test-mail',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "The route {$name} has no rate limit.",
            );
        }
    }
}
