<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\EnforceAccessLevel;
use App\Http\Middleware\EnforceReadAccess;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateTagTool;
use App\Mcp\Tools\ListNewslettersTool;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A Viewer has read-only access. An API token has explicit read and write
 * abilities. The web routes, the API and the MCP server enforce both.
 */
class AccessLevelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These routes change only the account of the signed-in person, so a
     * Viewer keeps them.
     *
     * @var list<string>
     */
    private const PERSONAL_ROUTES = [
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'api-tokens.destroy',
    ];

    public function test_viewer_cannot_write_through_the_web_routes(): void
    {
        $viewer = $this->member(UserRole::Viewer);

        $this->actingAs($viewer)->post(route('tags.store'), ['name' => 'blocked'])->assertForbidden();
        $this->actingAs($viewer)->post(route('lists.store'), ['name' => 'blocked'])->assertForbidden();
        $this->actingAs($viewer)->post(route('api-tokens.store'), ['name' => 'blocked'])->assertForbidden();

        $this->assertDatabaseMissing('tags', ['name' => 'blocked']);
        $this->assertDatabaseMissing('lists', ['name' => 'blocked']);
        $this->assertSame(0, $viewer->tokens()->count());
    }

    public function test_viewer_can_read_and_manage_the_own_account(): void
    {
        $viewer = $this->member(UserRole::Viewer);

        $this->actingAs($viewer)->get(route('contacts.index'))->assertOk();
        $this->actingAs($viewer)->get(route('tags.index'))->assertOk();
        $this->actingAs($viewer)
            ->postJson(route('newsletters.contacts.preview'), ['filters' => []])
            ->assertOk();
        $this->actingAs($viewer)
            ->patch(route('profile.update'), [
                'name' => 'Renamed viewer',
                'email' => $viewer->email,
                'timezone' => $viewer->client->timezone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed viewer', $viewer->fresh()->name);
    }

    public function test_editor_can_write_through_the_web_routes(): void
    {
        $editor = $this->member(UserRole::Editor);

        $this->actingAs($editor)->post(route('tags.store'), ['name' => 'by editor'])->assertRedirect();

        $this->assertDatabaseHas('tags', ['name' => 'by editor']);
    }

    public function test_every_write_route_of_the_app_enforces_the_access_level(): void
    {
        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => $this->isAuthenticatedAppWriteRoute($route))
            ->reject(fn (RoutingRoute $route): bool => in_array($route->getName(), self::PERSONAL_ROUTES, true))
            ->reject(fn (RoutingRoute $route): bool => $this->middlewareOf($route)
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, EnforceAccessLevel::class)
                    || $middleware === EnforceReadAccess::class))
            ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unguarded);
    }

    public function test_viewer_token_cannot_write_through_the_api(): void
    {
        $token = $this->member(UserRole::Viewer)->createToken('viewer')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tags')->assertOk();
        $this->resetAuth();
        $this->withToken($token)->postJson('/api/v1/tags', ['name' => 'blocked'])->assertForbidden();
        $this->resetAuth();
        $this->withToken($token)->postJson('/api/v1/newsletters/contacts/preview', ['filters' => []])->assertOk();

        $this->assertDatabaseMissing('tags', ['name' => 'blocked']);
    }

    public function test_a_token_without_read_access_cannot_use_the_audience_preview(): void
    {
        $token = User::factory()->create()->createToken('writer', ['write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/newsletters/contacts/preview', ['filters' => []])
            ->assertForbidden();
    }

    public function test_read_only_token_cannot_write_through_the_api(): void
    {
        $token = User::factory()->create()->createToken('reader', ['read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tags')->assertOk();
        $this->resetAuth();
        $this->withToken($token)->postJson('/api/v1/tags', ['name' => 'blocked'])->assertForbidden();

        $this->assertDatabaseMissing('tags', ['name' => 'blocked']);
    }

    public function test_token_without_the_read_ability_cannot_read_through_the_api(): void
    {
        $token = User::factory()->create()->createToken('nothing', [])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tags')->assertForbidden();
    }

    public function test_read_write_token_and_legacy_token_can_write_through_the_api(): void
    {
        $user = User::factory()->create();
        $readWrite = $user->createToken('writer', ['read', 'write'])->plainTextToken;
        $legacy = $user->createToken('legacy')->plainTextToken;

        $this->withToken($readWrite)->postJson('/api/v1/tags', ['name' => 'by writer'])->assertCreated();
        $this->resetAuth();
        $this->withToken($legacy)->postJson('/api/v1/tags', ['name' => 'by legacy'])->assertCreated();
    }

    public function test_mcp_hides_and_refuses_write_tools_for_a_viewer(): void
    {
        $viewer = $this->member(UserRole::Viewer);
        $token = $viewer->createToken('viewer')->plainTextToken;

        $tools = $this->mcpToolNames($token);

        $this->assertContains('list-newsletters-tool', $tools);
        $this->assertNotContains('create-tag-tool', $tools);

        ByoMailerServer::actingAs($viewer)->tool(CreateTagTool::class, ['name' => 'blocked'])->assertHasErrors();
        ByoMailerServer::actingAs($viewer)->tool(ListNewslettersTool::class, [])->assertOk();

        $this->assertDatabaseMissing('tags', ['name' => 'blocked']);
    }

    public function test_mcp_hides_write_tools_for_a_read_only_token(): void
    {
        $user = User::factory()->create();

        $readOnly = $this->mcpToolNames($user->createToken('reader', ['read'])->plainTextToken);
        $readWrite = $this->mcpToolNames($user->createToken('writer', ['read', 'write'])->plainTextToken);

        $this->assertContains('list-newsletters-tool', $readOnly);
        $this->assertNotContains('create-tag-tool', $readOnly);
        $this->assertContains('create-tag-tool', $readWrite);
    }

    public function test_mcp_shows_no_tools_to_a_token_without_the_read_ability(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->mcpToolNames($user->createToken('nothing', [])->plainTextToken));
    }

    private function member(UserRole $role): User
    {
        $admin = User::factory()->create();

        return User::factory()->create([
            'client_id' => $admin->client_id,
            'role' => $role->value,
        ]);
    }

    private function isAuthenticatedAppWriteRoute(RoutingRoute $route): bool
    {
        if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
            return false;
        }

        if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
            return false;
        }

        return $this->middlewareOf($route)
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, Authenticate::class));
    }

    /**
     * The middleware that runs for the route, after the route exclusions.
     *
     * @return Collection<int, string>
     */
    private function middlewareOf(RoutingRoute $route): Collection
    {
        return collect(app(Router::class)->gatherRouteMiddleware($route))
            ->filter(fn ($middleware): bool => is_string($middleware))
            ->values();
    }

    /**
     * Reads every page of `tools/list`.
     *
     * @return list<string>
     */
    private function mcpToolNames(string $token): array
    {
        $names = [];
        $cursor = null;

        do {
            $this->resetAuth();

            $response = $this->withToken($token)->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => $cursor === null ? (object) [] : ['cursor' => $cursor],
            ])->assertOk();

            $names = [...$names, ...collect($response->json('result.tools'))->pluck('name')->all()];
            $cursor = $response->json('result.nextCursor');
        } while ($cursor !== null);

        return $names;
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
