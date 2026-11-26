<?php

namespace Tests\Feature\Agent;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\EmailCredential;
use App\Models\User;
use App\Support\Instance\Heartbeats;
use App\Support\InstanceSetup;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

class StatusCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    private const STATUS_KEYS = ['version', 'app_url', 'environment', 'database', 'queue', 'scheduler', 'storage_link', 'mail_driver', 'cache_driver', 'mcp', 'ses', 'owner_exists', 'setup_url'];

    public function test_status_returns_every_field_of_the_contract(): void
    {
        config(['queue.default' => 'database', 'app.version' => '0.1.0']);

        [$exit, $json] = $this->runJson('byomailer:status');

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame(self::STATUS_KEYS, array_keys($data));
        $this->assertSame('0.1.0', $data['version']);
        $this->assertSame(['driver' => DB::connection()->getDriverName(), 'connected' => true, 'pending_migrations' => 0, 'error' => null], $data['database']);
        $this->assertSame(['connection' => 'database', 'worker' => 'queue:work', 'heartbeat_at' => null, 'healthy' => false], $data['queue']);
        $this->assertSame(['last_run_at' => null, 'healthy' => false], $data['scheduler']);
        $this->assertSame([
            'enabled' => true,
            'url' => url('/mcp'),
            'admin_stdio_command' => 'php artisan mcp:start byomailer-admin',
            'admin_http_enabled' => false,
        ], $data['mcp']);
        $this->assertSame(['accounts' => 0, 'accounts_with_credentials' => 0, 'verified_identities' => 0, 'events_configured' => false], $data['ses']);
        $this->assertFalse($data['owner_exists']);
        $this->assertSame(InstanceSetup::url(), $data['setup_url']);
    }

    public function test_status_names_horizon_as_the_worker_for_the_redis_queue(): void
    {
        config(['queue.default' => 'redis']);

        [, $json] = $this->runJson('byomailer:status');

        $this->assertSame('horizon', $json['data']['queue']['worker']);
    }

    public function test_status_counts_the_ses_state_and_never_shows_a_key(): void
    {
        $owner = User::factory()->owner()->create();
        $credential = EmailCredential::factory()->create(['user_id' => $owner->id, 'client_id' => $owner->client_id]);

        [, $json, $raw] = $this->runJson('byomailer:status');

        $this->assertSame(1, $json['data']['ses']['accounts_with_credentials']);
        $this->assertTrue($json['data']['owner_exists']);
        $this->assertNull($json['data']['setup_url']);
        $this->assertStringNotContainsString($credential->access_key, $raw);
        $this->assertStringNotContainsString($credential->secret_key, $raw);
    }

    public function test_status_reads_fresh_and_stale_heartbeats(): void
    {
        config(['queue.default' => 'database']);
        Heartbeats::beat(Heartbeats::SCHEDULER);
        (new RecordQueueHeartbeat)->handle();

        [, $json] = $this->runJson('byomailer:status');
        $this->assertTrue($json['data']['queue']['healthy']);
        $this->assertTrue($json['data']['scheduler']['healthy']);

        $this->travel(Heartbeats::STALE_AFTER_SECONDS + 1)->seconds();

        [, $json] = $this->runJson('byomailer:status');
        $this->assertFalse($json['data']['queue']['healthy']);
        $this->assertFalse($json['data']['scheduler']['healthy']);
        $this->assertNotNull($json['data']['queue']['heartbeat_at']);
    }

    public function test_status_exits_one_with_db_unreachable_when_the_database_does_not_answer(): void
    {
        config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']]);
        $previous = config('database.default');
        config(['database.default' => 'broken']);

        try {
            [$exit, $json] = $this->runJson('byomailer:status');
        } finally {
            config(['database.default' => $previous]);
        }

        $this->assertSame(1, $exit);
        $this->assertFalse($json['data']['database']['connected']);
        $this->assertSame('db_unreachable', $json['errors'][0]['code']);
    }

    public function test_the_scheduler_sends_both_heartbeats_each_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event) => $event->description);

        $this->assertSame('* * * * *', $events['byomailer:scheduler-heartbeat']->expression);
        $this->assertSame('* * * * *', $events['byomailer:queue-heartbeat']->expression);
    }

    public function test_the_text_output_shows_the_main_values(): void
    {
        $this->artisan('byomailer:status')
            ->expectsOutputToContain('Database')
            ->expectsOutputToContain('SES')
            ->assertSuccessful();
    }
}
