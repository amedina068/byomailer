<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

class TokenCommandsTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create(['email' => 'owner@example.com']);
    }

    public function test_create_makes_a_read_and_write_token_for_the_first_owner_by_default(): void
    {
        [$exit, $json] = $this->runJson('byomailer:token:create', ['--name' => 'agent']);

        $this->assertSame(0, $exit);
        $token = $json['data']['token'];
        $this->assertSame(['read', 'write'], $token['abilities']);
        $this->assertSame(['id' => $this->owner->id, 'email' => 'owner@example.com'], $token['user']);
        $this->assertNull($token['expires_at']);
        $this->assertTrue(PersonalAccessToken::findToken($token['value'])->tokenable->is($this->owner));
    }

    public function test_create_accepts_a_read_only_token_and_an_expiry(): void
    {
        [$exit, $json] = $this->runJson('byomailer:token:create', ['--name' => 'reader', '--abilities' => 'read', '--expires' => '30d']);

        $this->assertSame(0, $exit);
        $this->assertSame(['read'], $json['data']['token']['abilities']);
        $this->assertNotNull($json['data']['token']['expires_at']);
    }

    public function test_an_instance_admin_token_needs_an_owner_and_a_short_expiry(): void
    {
        [$exit, $json] = $this->runJson('byomailer:token:create', ['--name' => 'ops', '--abilities' => 'instance_admin']);
        $this->assertSame(0, $exit);
        $this->assertSame(['instance_admin'], $json['data']['token']['abilities']);
        $this->assertNotNull($json['data']['token']['expires_at']);

        $cases = [
            [['--name' => 'ops', '--abilities' => 'instance_admin', '--expires' => '30d'], '7 days'],
            [['--name' => 'ops', '--abilities' => 'instance_admin,read'], 'other abilities'],
            [['--name' => 'ops', '--abilities' => 'admin'], 'Unknown ability'],
            [['--name' => 'ops', '--abilities' => 'instance_admin', '--user' => User::factory()->create()->email], 'owner'],
            [['--name' => 'ops', '--expires' => 'soon'], 'not valid'],
        ];

        foreach ($cases as [$parameters, $text]) {
            [$exit, $json] = $this->runJson('byomailer:token:create', $parameters);

            $this->assertSame(2, $exit, $text);
            $this->assertSame('invalid_token_request', $json['errors'][0]['code']);
            $this->assertStringContainsString($text, $json['errors'][0]['message']);
        }
    }

    public function test_create_needs_a_name_and_a_known_user(): void
    {
        [$exit, $json] = $this->runJson('byomailer:token:create', []);
        $this->assertSame(2, $exit);
        $this->assertSame('missing_option', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('byomailer:token:create', ['--name' => 'x', '--user' => 'nobody@example.com']);
        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);
    }

    public function test_list_never_shows_a_token_value(): void
    {
        $plain = $this->owner->createToken('agent', ['read', 'write'])->plainTextToken;
        User::factory()->create()->createToken('other', ['read']);

        [$exit, $json, $raw] = $this->runJson('byomailer:token:list');

        $this->assertSame(0, $exit);
        $this->assertCount(2, $json['data']['tokens']);
        $this->assertSame(['id', 'name', 'user', 'abilities', 'last_used_at', 'expires_at', 'created_at'], array_keys($json['data']['tokens'][0]));
        $this->assertStringNotContainsString(explode('|', $plain)[1], $raw);

        [, $json] = $this->runJson('byomailer:token:list', ['--user' => 'owner@example.com']);
        $this->assertSame(['agent'], array_column($json['data']['tokens'], 'name'));
    }

    public function test_revoke_is_idempotent(): void
    {
        $token = $this->owner->createToken('agent', ['read']);

        [$exit, $json] = $this->runJson('byomailer:token:revoke', ['id' => (string) $token->accessToken->id]);
        $this->assertSame(0, $exit);
        $this->assertSame('revoked', $json['data']['status']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        [$exit, $json] = $this->runJson('byomailer:token:revoke', ['id' => (string) $token->accessToken->id]);
        $this->assertSame(0, $exit);
        $this->assertSame('absent', $json['data']['status']);

        [$exit, $json] = $this->runJson('byomailer:token:revoke', ['id' => 'abc']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_id', $json['errors'][0]['code']);
    }

    public function test_an_old_star_token_keeps_full_product_access(): void
    {
        $token = $this->owner->createToken('legacy')->plainTextToken;

        $tools = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools');

        $this->assertContains('create-newsletter-tool', array_column($tools, 'name'));
    }
}
