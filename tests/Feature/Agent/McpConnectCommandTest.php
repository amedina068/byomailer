<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

class McpConnectCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    public function test_claude_returns_the_command_that_reads_the_token_from_the_environment(): void
    {
        [$exit, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'claude']);

        $this->assertSame(0, $exit);
        $this->assertSame('claude', $json['data']['client']);
        $this->assertSame('claude mcp add --transport http byomailer '.url('/mcp').' --header "Authorization: Bearer ${BYOMAILER_TOKEN}"', $json['data']['command']);
        $this->assertNull($json['data']['env']);
        $this->assertNull($json['data']['token']);
        $this->assertSame('ssh <host> docker exec -i <container> php artisan mcp:start byomailer-admin', $json['data']['admin']['stdio']);
    }

    public function test_codex_cursor_and_json_have_their_own_shape(): void
    {
        [, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'codex']);
        $this->assertSame('codex mcp add byomailer --url '.url('/mcp').' --bearer-token-env-var BYOMAILER_TOKEN', $json['data']['command']);
        $this->assertStringContainsString('[mcp_servers.byomailer]', $json['data']['config_toml']);

        [, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'cursor']);
        $this->assertSame('Bearer ${env:BYOMAILER_TOKEN}', $json['data']['config']['mcpServers']['byomailer']['headers']['Authorization']);

        [, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'json']);
        $this->assertSame('http', $json['data']['config']['mcpServers']['byomailer']['type']);
        $this->assertSame(url('/mcp'), $json['data']['config']['mcpServers']['byomailer']['url']);
    }

    public function test_token_new_creates_a_product_token_for_the_owner(): void
    {
        $owner = User::factory()->owner()->create();

        [$exit, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'json', '--token' => 'new']);

        $this->assertSame(0, $exit);
        $value = $json['data']['token']['value'];
        $this->assertSame(['read', 'write'], $json['data']['token']['abilities']);
        $this->assertSame("export BYOMAILER_TOKEN='{$value}'", $json['data']['env']);
        $this->assertSame("Bearer {$value}", $json['data']['config']['mcpServers']['byomailer']['headers']['Authorization']);
        $this->assertTrue(PersonalAccessToken::findToken($value)->tokenable->is($owner));
    }

    public function test_bad_input_exits_two(): void
    {
        [$exit, $json] = $this->runJson('byomailer:mcp:connect', ['--client' => 'emacs']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_client', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('byomailer:mcp:connect', ['--token' => 'old']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_token_option', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('byomailer:mcp:connect', ['--token' => 'new']);
        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);
    }

    public function test_the_text_output_prints_the_command(): void
    {
        $this->artisan('byomailer:mcp:connect', ['--client' => 'claude'])
            ->expectsOutputToContain('claude mcp add --transport http byomailer')
            ->assertSuccessful();
    }
}
