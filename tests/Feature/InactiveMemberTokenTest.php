<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The web routes refuse an archived or invited member. A Sanctum token
 * must not give that member access to the API or to the MCP server.
 */
class InactiveMemberTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_of_an_archived_member_is_refused_on_the_api_and_mcp(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create(['client_id' => $admin->client_id, 'role' => UserRole::Editor->value]);
        $contact = Contact::factory()->for($admin)->create();
        $token = $member->createToken('agent')->plainTextToken;

        $member->forceFill(['archived_at' => now()])->save();

        $this->withToken($token)->getJson('/api/v1/contacts/'.$contact->id)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/lists', ['name' => 'by archived'])->assertUnauthorized();
        $this->callMcp($token)->assertUnauthorized();
    }

    public function test_token_of_a_member_with_a_pending_invite_is_refused(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create([
            'client_id' => $admin->client_id,
            'role' => UserRole::Editor->value,
            'invite_expires_at' => now()->addDay(),
        ]);
        $token = $member->createToken('agent')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/contacts')->assertUnauthorized();
        $this->callMcp($token)->assertUnauthorized();
    }

    public function test_token_of_an_active_member_still_works(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('agent')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/contacts')->assertOk();
        $this->callMcp($token)->assertOk();
    }

    public function test_archiving_a_member_deletes_the_tokens_of_the_member(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create(['client_id' => $admin->client_id, 'role' => UserRole::Editor->value]);
        $member->createToken('agent');
        $admin->createToken('admin-agent');

        $this->actingAs($admin)->post(route('team.archive', $member))->assertRedirect();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());
    }

    private function callMcp(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);
    }
}
