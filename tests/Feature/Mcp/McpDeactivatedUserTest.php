<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MCP server authenticates with Sanctum tokens. The token of a deactivated
 * user stops working there too. Reactivation restores it.
 */
class McpDeactivatedUserTest extends TestCase
{
    use RefreshDatabase;

    private function callMcp(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);
    }

    public function test_a_token_stops_working_on_the_mcp_server_and_works_again_after_reactivation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('agent')->plainTextToken;

        $this->callMcp($token)->assertOk();

        $user->deactivate();
        $this->callMcp($token)->assertUnauthorized();

        $user->reactivate();
        $this->callMcp($token)->assertOk();
    }
}
