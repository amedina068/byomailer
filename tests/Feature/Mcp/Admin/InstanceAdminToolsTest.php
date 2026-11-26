<?php

namespace Tests\Feature\Mcp\Admin;

use App\Mail\SesTestMail;
use App\Mail\UserInviteMail;
use App\Mcp\Admin\Tools\CreateApiTokenTool;
use App\Mcp\Admin\Tools\GetInstanceStatusTool;
use App\Mcp\Admin\Tools\GetQueueHealthTool;
use App\Mcp\Admin\Tools\GetSesStatusTool;
use App\Mcp\Admin\Tools\GetSettingsTool;
use App\Mcp\Admin\Tools\InviteUserTool;
use App\Mcp\Admin\Tools\ListAccountsTool;
use App\Mcp\Admin\Tools\ListApiTokensTool;
use App\Mcp\Admin\Tools\ListUsersTool;
use App\Mcp\Admin\Tools\ReadRecentErrorsTool;
use App\Mcp\Admin\Tools\RetryFailedJobsTool;
use App\Mcp\Admin\Tools\RevokeApiTokenTool;
use App\Mcp\Admin\Tools\RunDoctorTool;
use App\Mcp\Admin\Tools\SendTestEmailTool;
use App\Mcp\Admin\Tools\SetSettingTool;
use App\Mcp\Servers\InstanceAdminServer;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\UserMailerFactory;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class InstanceAdminToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The wire name and the annotations of each tool. A destructive tool must
     * also return a preview first (tested below).
     */
    private const TOOLS = [
        'get-instance-status' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'run-doctor' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'list-users' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'list-accounts' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'invite-user' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'list-api-tokens' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'create-api-token' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'revoke-api-token' => ['readOnlyHint' => false, 'destructiveHint' => true],
        'get-settings' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'set-setting' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'get-queue-health' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'retry-failed-jobs' => ['readOnlyHint' => false, 'destructiveHint' => true],
        'read-recent-errors' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'get-ses-status' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'send-test-email' => ['readOnlyHint' => false, 'destructiveHint' => false],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create(['name' => 'Instance Owner', 'email' => 'owner@example.com']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TestResponse $response): array
    {
        $raw = (fn () => $this->response->toArray())->call($response);

        return json_decode($raw['result']['content'][0]['text'], true);
    }

    private function isError(TestResponse $response): bool
    {
        return (fn () => $this->response->toArray())->call($response)['result']['isError'];
    }

    public function test_the_stdio_server_is_registered_with_the_documented_handle(): void
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('byomailer-admin'));
        $this->assertSame('byomailer-admin', InstanceAdminServer::HANDLE);
    }

    public function test_the_tool_list_and_the_annotations_are_the_contract(): void
    {
        $tools = (new ReflectionClass(InstanceAdminServer::class))->getProperty('tools')->getDefaultValue();
        $names = [];

        foreach ($tools as $class) {
            $tool = app($class);
            $names[] = $tool->name();
            $annotations = $tool->annotations();

            foreach (self::TOOLS[$tool->name()] ?? [] as $key => $value) {
                $this->assertSame($value, $annotations[$key] ?? null, "{$tool->name()} {$key}");
            }
        }

        $this->assertSame(array_keys(self::TOOLS), $names);
    }

    public function test_get_instance_status_returns_the_status_snapshot(): void
    {
        $data = $this->payload(InstanceAdminServer::tool(GetInstanceStatusTool::class));

        $this->assertTrue($data['owner_exists']);
        $this->assertTrue($data['database']['connected']);
        $this->assertArrayHasKey('ses', $data);
    }

    public function test_run_doctor_returns_checks_and_a_summary_and_runs_no_fix(): void
    {
        $data = $this->payload(InstanceAdminServer::tool(RunDoctorTool::class));

        $ids = array_column($data['checks'], 'id');
        $this->assertContains('ses_credentials', $ids);
        $this->assertContains('ses_events', $ids);
        $this->assertSame(count($ids), array_sum($data['summary']));
        $this->assertArrayNotHasKey('fixed', $data['checks'][0]);
    }

    public function test_list_users_and_list_accounts_cover_every_account(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);
        EmailCredential::factory()->create(['user_id' => $other->id, 'client_id' => $other->client_id, 'region' => 'eu-west-1']);

        $users = $this->payload(InstanceAdminServer::tool(ListUsersTool::class))['users'];
        $this->assertSame(['owner@example.com', 'other@example.com'], array_column($users, 'email'));

        $accounts = $this->payload(InstanceAdminServer::tool(ListAccountsTool::class))['accounts'];
        $this->assertCount(2, $accounts);
        $this->assertSame(['id', 'name', 'users_count', 'admins', 'ses', 'created_at'], array_keys($accounts[0]));
        $this->assertFalse($accounts[0]['ses']['credentials']);
        $this->assertTrue($accounts[1]['ses']['credentials']);
        $this->assertSame('eu-west-1', $accounts[1]['ses']['region']);
    }

    public function test_get_ses_status_reports_each_account_with_no_secret(): void
    {
        $credential = EmailCredential::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id, 'quota_fetched_at' => now()]);
        EmailIdentity::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id, 'email' => 'news@example.com']);

        $response = InstanceAdminServer::tool(GetSesStatusTool::class, ['account_id' => $this->owner->client_id]);
        $data = $this->payload($response);

        $this->assertCount(1, $data['accounts']);
        $account = $data['accounts'][0];
        $this->assertTrue($account['credentials']);
        $this->assertSame(1, $account['identities']['verified']);
        $this->assertSame(['news@example.com'], $account['identities']['verified_emails']);
        $this->assertFalse($data['events']['configured']);
        $this->assertContains('SES_EVENTS_SQS_QUEUE_URL', $data['events']['missing']);
        $response->assertDontSee($credential->access_key)->assertDontSee($credential->secret_key);
    }

    public function test_invite_user_returns_a_link_and_sends_the_invitation(): void
    {
        Mail::fake();

        $data = $this->payload(InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'New@Example.com', 'name' => 'New Person']));

        $this->assertSame('created', $data['status']);
        $this->assertSame('new@example.com', $data['email']);
        $this->assertSame($this->owner->client_id, $data['account']['id']);
        $this->assertSame('editor', $data['role']);
        $this->assertStringContainsString('/invites/', $data['invite_url']);
        $this->assertTrue($data['email_sent']);
        Mail::assertSent(UserInviteMail::class, fn ($mail) => $mail->hasTo('new@example.com'));

        $this->assertTrue($this->isError(InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'owner@example.com', 'name' => 'Again'])));
    }

    public function test_token_tools_create_product_tokens_only_and_revoke_after_a_preview(): void
    {
        $data = $this->payload(InstanceAdminServer::tool(CreateApiTokenTool::class, ['name' => 'agent']));
        $this->assertSame(['read', 'write'], $data['token']['abilities']);
        $id = $data['token']['id'];

        $response = InstanceAdminServer::tool(CreateApiTokenTool::class, ['name' => 'ops', 'abilities' => ['instance_admin']]);
        $this->assertTrue($this->isError($response));

        $tokens = $this->payload(InstanceAdminServer::tool(ListApiTokensTool::class))['tokens'];
        $this->assertSame([$id], array_column($tokens, 'id'));
        $this->assertArrayNotHasKey('value', $tokens[0]);

        $preview = $this->payload(InstanceAdminServer::tool(RevokeApiTokenTool::class, ['token_id' => $id]));
        $this->assertTrue($preview['confirm_required']);
        $this->assertSame(1, PersonalAccessToken::query()->count());

        $done = $this->payload(InstanceAdminServer::tool(RevokeApiTokenTool::class, ['token_id' => $id, 'confirm' => true]));
        $this->assertSame('revoked', $done['status']);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_settings_change_only_through_the_allowlist(): void
    {
        $settings = $this->payload(InstanceAdminServer::tool(GetSettingsTool::class));
        $this->assertSame(Registration::MODES, $settings['settings']['registration_mode']['allowed']);

        $changed = $this->payload(InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'registration_mode', 'value' => 'invite_only']));
        $this->assertSame('invite_only', $changed['value']);
        $this->assertSame(Registration::INVITE_ONLY, Registration::mode());

        $this->assertTrue($this->isError(InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'app_url', 'value' => 'http://evil.example.com'])));
        $this->assertTrue($this->isError(InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'registration_mode', 'value' => 'everyone'])));
    }

    public function test_queue_health_and_retry_failed_jobs_with_a_preview(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'uuid' => $uuid]),
            'exception' => "RuntimeException: password=hunter2 failed\n#0 trace",
            'failed_at' => now(),
        ]);

        $health = $this->payload(InstanceAdminServer::tool(GetQueueHealthTool::class));
        $this->assertSame(1, $health['failed']['count']);
        $this->assertStringNotContainsString('hunter2', $health['failed']['newest'][0]['error']);

        $preview = $this->payload(InstanceAdminServer::tool(RetryFailedJobsTool::class, ['uuids' => [$uuid]]));
        $this->assertTrue($preview['confirm_required']);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->assertTrue($this->isError(InstanceAdminServer::tool(RetryFailedJobsTool::class, [])));
    }

    public function test_read_recent_errors_redacts_secrets(): void
    {
        $log = storage_path('logs/laravel.log');
        $before = File::exists($log) ? File::get($log) : null;
        File::put($log, '['.now()->format('Y-m-d H:i:s')."] testing.ERROR: SES failed with secret=abc123secret and Bearer 1|abcdefghijklmnopqrstuvwxyz\n");

        try {
            $data = $this->payload(InstanceAdminServer::tool(ReadRecentErrorsTool::class));
        } finally {
            $before === null ? File::delete($log) : File::put($log, $before);
        }

        $this->assertCount(1, $data['entries']);
        $this->assertStringNotContainsString('abc123secret', $data['entries'][0]['message']);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $data['entries'][0]['message']);
    }

    public function test_send_test_email_explains_how_to_set_up_ses_when_it_is_missing(): void
    {
        $response = InstanceAdminServer::tool(SendTestEmailTool::class);

        $this->assertTrue($this->isError($response));
        $response->assertSee('ses_not_configured')->assertSee('/settings/email-credentials');

        EmailCredential::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id]);

        InstanceAdminServer::tool(SendTestEmailTool::class)->assertSee('no_verified_identity');
    }

    public function test_send_test_email_sends_through_the_ses_mailer_of_the_account(): void
    {
        Mail::fake();
        EmailCredential::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id, 'region' => 'eu-west-1']);
        EmailIdentity::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id, 'email' => 'news@example.com']);

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->once()->with(Mockery::on(fn (User $user) => $user->is($this->owner)))->andReturn(new PendingMail(Mail::mailer()));
        $this->app->instance(UserMailerFactory::class, $factory);

        $data = $this->payload(InstanceAdminServer::tool(SendTestEmailTool::class, ['to' => 'inbox@example.com']));

        $this->assertTrue($data['sent']);
        $this->assertSame('news@example.com', $data['from']);
        $this->assertSame('eu-west-1', $data['region']);
        Mail::assertSent(SesTestMail::class, fn (SesTestMail $mail) => $mail->hasTo('inbox@example.com') && $mail->hasFrom('news@example.com'));
    }

    public function test_send_test_email_returns_the_ses_error_with_a_fix(): void
    {
        EmailCredential::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id]);
        EmailIdentity::factory()->create(['user_id' => $this->owner->id, 'client_id' => $this->owner->client_id]);

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andThrow(new RuntimeException('Email address is not verified. secret=abc123secret'));
        $this->app->instance(UserMailerFactory::class, $factory);

        $response = InstanceAdminServer::tool(SendTestEmailTool::class);

        $this->assertTrue($this->isError($response));
        $response->assertSee('ses_send_failed')->assertDontSee('abc123secret');
    }

    public function test_each_call_writes_one_audit_line_with_no_secret(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
            if ($event->message === 'mcp-admin call') {
                $lines[] = $event->context;
            }
        });

        InstanceAdminServer::tool(CreateApiTokenTool::class, ['name' => 'agent']);

        $this->assertCount(1, $lines);
        $this->assertSame('create-api-token', $lines[0]['tool']);
        $this->assertSame('stdio', $lines[0]['transport']);
        $this->assertTrue($lines[0]['ok']);
        $this->assertStringNotContainsString('|', json_encode($lines[0]));
    }
}
