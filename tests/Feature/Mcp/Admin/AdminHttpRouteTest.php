<?php

namespace Tests\Feature\Mcp\Admin;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `/mcp/admin` with MCP_ADMIN_ENABLED=true. The flag is read while the routes
 * register, so this class boots each application with the flag already on.
 */
class AdminHttpRouteTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('mcp.admin_enabled', true);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function listTools(?string $token, string $path = '/mcp/admin'): TestResponse
    {
        $request = $token ? $this->withHeader('Authorization', "Bearer {$token}") : $this;

        return $request->postJson($path, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    public function test_an_owner_with_a_short_lived_admin_token_can_call_the_server(): void
    {
        $owner = User::factory()->owner()->create();
        $token = $owner->createToken('ops', ['instance_admin'], now()->addDays(7))->plainTextToken;

        $names = collect($this->listTools($token)->assertOk()->json('result.tools'))->pluck('name');
        $this->assertCount(15, $names);
        $this->assertContains('get-ses-status', $names);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list-users', 'arguments' => []]])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }

    public function test_every_other_credential_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->listTools(null)->assertUnauthorized();

        $refused = [
            'a product token' => $owner->createToken('p', ['read', 'write'])->plainTextToken,
            'a * token that expires in a day' => $owner->createToken('star', ['*'], now()->addDay())->plainTextToken,
            'an admin token with no expiry' => $owner->createToken('x', ['instance_admin'])->plainTextToken,
            'an admin token that lives 30 days' => $owner->createToken('y', ['instance_admin'], now()->addDays(30))->plainTextToken,
            'an admin token of a member' => $member->createToken('z', ['instance_admin'], now()->addDay())->plainTextToken,
        ];

        foreach ($refused as $label => $token) {
            $this->assertSame(403, $this->listTools($token)->status(), $label);
            $this->app['auth']->forgetGuards();
        }

        $expired = $owner->createToken('old', ['instance_admin'], now()->addHour())->plainTextToken;
        $this->travel(2)->hours();
        $this->listTools($expired)->assertUnauthorized();
    }

    public function test_an_admin_token_gets_no_tool_of_the_product_server(): void
    {
        $owner = User::factory()->owner()->create();
        $token = $owner->createToken('ops', ['instance_admin'], now()->addDay())->plainTextToken;

        $this->assertSame([], $this->listTools($token, '/mcp')->assertOk()->json('result.tools'));
    }
}
