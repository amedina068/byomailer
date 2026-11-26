<?php

namespace Tests\Feature\Agent;

use App\Models\Client;
use App\Models\User;
use App\Support\InstanceSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

/**
 * `--json` on the commands that existed before the agent-first contract:
 * `byomailer:setup-url`, `byomailer:user:list` and `byomailer:user:password`.
 */
class ExistingCommandsJsonTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    public function test_setup_url_returns_the_url_while_setup_is_needed(): void
    {
        [$exit, $json] = $this->runJson('byomailer:setup-url');

        $this->assertSame(0, $exit);
        $this->assertSame(['setup_needed' => true, 'url' => InstanceSetup::url()], $json['data']);
    }

    public function test_setup_url_returns_null_after_an_account_exists(): void
    {
        User::factory()->create();

        [$exit, $json] = $this->runJson('byomailer:setup-url');

        $this->assertSame(0, $exit);
        $this->assertSame(['setup_needed' => false, 'url' => null], $json['data']);
    }

    public function test_user_list_returns_every_user_with_a_stable_shape(): void
    {
        $client = Client::factory()->create(['name' => 'Olive Studio']);
        $owner = User::factory()->owner()->create(['name' => 'Olive Owner', 'email' => 'owner@example.com', 'client_id' => $client->id]);
        User::factory()->teammateOf($owner)->create(['email' => 'member@example.com', 'deactivated_at' => now()]);

        [$exit, $json] = $this->runJson('byomailer:user:list');

        $this->assertSame(0, $exit);
        $users = $json['data']['users'];
        $this->assertTrue(array_is_list($users));
        $this->assertCount(2, $users);
        $this->assertSame(['id', 'name', 'email', 'account', 'role', 'is_owner', 'deactivated', 'invited', 'email_verified', 'created_at'], array_keys($users[0]));
        $this->assertSame($owner->id, $users[0]['id']);
        $this->assertSame(['id' => $client->id, 'name' => 'Olive Studio'], $users[0]['account']);
        $this->assertTrue($users[0]['is_owner']);
        $this->assertSame('admin', $users[0]['role']);
        $this->assertSame('editor', $users[1]['role']);
        $this->assertTrue($users[1]['deactivated']);
    }

    public function test_user_list_returns_an_empty_list_when_nothing_matches(): void
    {
        [$exit, $json] = $this->runJson('byomailer:user:list', ['--search' => 'nobody']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $json['data']['users']);
    }

    public function test_user_password_generate_returns_the_password_one_time(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json] = $this->runJson('byomailer:user:password', ['email' => 'member@example.com', '--generate' => true]);

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame(['user', 'password_changed', 'generated_password', 'remember_token_rotated', 'web_sessions_ended', 'two_factor_reset'], array_keys($data));
        $this->assertTrue(Hash::check($data['generated_password'], $user->fresh()->password));
        $this->assertFalse($data['two_factor_reset']);
    }

    public function test_user_password_with_a_given_password_does_not_echo_it(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json, $raw] = $this->runJson('byomailer:user:password', ['email' => 'member@example.com', '--password' => 'a-long-password-1']);

        $this->assertSame(0, $exit);
        $this->assertNull($json['data']['generated_password']);
        $this->assertStringNotContainsString('a-long-password-1', $raw);
    }

    public function test_user_password_bad_input_exits_two(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        $cases = [
            [['email' => 'nobody@example.com', '--generate' => true], 'user_not_found'],
            [['email' => 'member@example.com', '--generate' => true, '--password' => 'a-long-password-1'], 'conflicting_options'],
            // JSON mode never prompts.
            [['email' => 'member@example.com'], 'missing_option'],
            [['email' => 'member@example.com', '--password' => 'short'], 'invalid_password'],
        ];

        foreach ($cases as [$parameters, $code]) {
            [$exit, $json] = $this->runJson('byomailer:user:password', $parameters);

            $this->assertSame(2, $exit, $code);
            $this->assertSame($code, $json['errors'][0]['code']);
        }
    }
}
