<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

class InstallJsonTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    public function test_a_fresh_install_returns_the_owner_and_a_login_url_with_no_password(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com']);

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame('created', $data['status']);
        $this->assertSame(['id', 'name', 'email', 'account'], array_keys($data['owner']));
        $this->assertSame('owner@example.com', $data['owner']['email']);
        $this->assertSame(config('app.url'), $data['app_url']);
        $this->assertFalse($data['password_set']);
        $this->assertStringContainsString('/login/link/', $data['login_url']);
        $this->assertSame(15, $data['login_url_expires_in_minutes']);
        $this->assertSame('/settings/email-credentials', parse_url($data['next_steps']['ses_credentials_url'], PHP_URL_PATH));
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('connect', $data);
        $this->assertSame([], $json['errors']);

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($owner->isOwner());
        $this->assertSame($owner->client_id, $data['owner']['account']['id']);
    }

    public function test_the_login_url_signs_the_owner_in_one_time(): void
    {
        [, $json] = $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com']);
        $url = $json['data']['login_url'];
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->get($path)->assertRedirect(config('fortify.home'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'owner@example.com')->firstOrFail());

        auth()->logout();
        $this->get($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_with_token_returns_a_product_token_and_a_working_connect_line(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', [
            '--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => 'agent',
        ]);

        $this->assertSame(0, $exit);
        $token = $json['data']['token'];
        $this->assertSame(['id', 'name', 'user', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'value'], array_keys($token));
        $this->assertSame('agent', $token['name']);
        $this->assertSame(['read', 'write'], $token['abilities']);
        $this->assertNull($token['expires_at']);

        $connect = $json['data']['connect'];
        $this->assertSame(['mcp_url', 'token_env', 'env', 'claude', 'codex'], array_keys($connect));
        $this->assertSame(url('/mcp'), $connect['mcp_url']);
        $this->assertSame('claude mcp add --transport http byomailer '.url('/mcp').' --header "Authorization: Bearer ${BYOMAILER_TOKEN}"', $connect['claude']);
        $this->assertStringNotContainsString($token['value'], $connect['claude']);
        $this->assertSame('codex mcp add byomailer --url '.url('/mcp').' --bearer-token-env-var BYOMAILER_TOKEN', $connect['codex']);
        $this->assertSame("export BYOMAILER_TOKEN='{$token['value']}'", $connect['env']);

        // The token of the export line opens the product MCP server with every tool.
        $tools = $this->withHeader('Authorization', 'Bearer '.$token['value'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools');

        $this->assertContains('create-newsletter-tool', array_column($tools, 'name'));
    }

    public function test_a_second_run_returns_exists_and_exits_zero_with_no_second_owner(): void
    {
        $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com']);

        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'Second', '--email' => 'second@example.com']);

        $this->assertSame(0, $exit);
        $this->assertSame('exists', $json['data']['status']);
        $this->assertSame('owner@example.com', $json['data']['owner']['email']);
        $this->assertNull($json['data']['password_set']);
        $this->assertNotEmpty($json['data']['login_url']);
        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
        $this->assertSame(1, User::query()->where('is_owner', true)->count());
    }

    public function test_a_second_run_with_a_token_creates_a_new_token_for_the_existing_owner(): void
    {
        $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => 'agent']);
        [$exit, $json] = $this->runJson('byomailer:install', ['--with-token' => 'agent']);

        $this->assertSame(0, $exit);
        $this->assertSame('exists', $json['data']['status']);
        $this->assertSame(2, PersonalAccessToken::query()->where('name', 'agent')->count());
    }

    public function test_users_with_no_owner_are_a_conflict(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'New', '--email' => 'new@example.com']);

        $this->assertSame(3, $exit);
        $this->assertSame('users_without_owner', $json['errors'][0]['code']);
        $this->assertStringContainsString('--promote', $json['errors'][0]['fix']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_missing_name_or_email_is_bad_input(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'Olive Owner']);

        $this->assertSame(2, $exit);
        $this->assertFalse($json['ok']);
        $this->assertSame('missing_option', $json['errors'][0]['code']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_invalid_email_is_bad_input_and_creates_nothing(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'not-an-email']);

        $this->assertSame(2, $exit);
        $this->assertSame('invalid_input', $json['errors'][0]['code']);
        $this->assertSame('invalid', $json['data']['status']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_deprecated_password_option_still_sets_the_password(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', [
            '--name' => 'Olive Owner', '--email' => 'owner@example.com', '--password' => 'a-long-password-1',
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($json['data']['password_set']);
        $this->assertTrue(Hash::check('a-long-password-1', User::query()->where('email', 'owner@example.com')->firstOrFail()->password));
    }

    public function test_an_empty_token_name_is_bad_input(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => ' ']);

        $this->assertSame(2, $exit);
        $this->assertSame('invalid_token_name', $json['errors'][0]['code']);
    }

    public function test_prepare_returns_what_it_prepared_and_creates_no_account(): void
    {
        [$exit, $json] = $this->runJson('byomailer:install', ['--prepare' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('prepared', $json['data']['status']);
        $this->assertContains($json['data']['prepared']['storage_link'], ['exists', 'created']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_promote_uses_the_contract_exit_codes(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json] = $this->runJson('byomailer:install', ['--promote' => 'nobody@example.com']);
        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('byomailer:install', ['--promote' => 'member@example.com']);
        $this->assertSame(0, $exit);
        $this->assertSame('promoted', $json['data']['status']);

        [$exit, $json] = $this->runJson('byomailer:install', ['--promote' => 'member@example.com']);
        $this->assertSame(0, $exit);
        $this->assertSame('unchanged', $json['data']['status']);
    }
}
