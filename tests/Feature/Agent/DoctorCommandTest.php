<?php

namespace Tests\Feature\Agent;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Support\Instance\Heartbeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsJsonCommands;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    private const CHECK_IDS = [
        'app_key', 'database', 'migrations', 'storage_writable', 'storage_link',
        'queue_heartbeat', 'scheduler_heartbeat', 'cache_store', 'redis', 'mail', 'owner',
        'ses_credentials', 'ses_region', 'ses_quota', 'ses_identity', 'ses_events', 'geoip',
    ];

    private const SES_CHECKS = ['ses_credentials', 'ses_region', 'ses_quota', 'ses_identity', 'ses_events'];

    public function test_a_new_instance_has_no_failure_and_each_ses_check_is_a_warning_with_a_fix(): void
    {
        User::factory()->owner()->create();

        [$exit, $json] = $this->runJson('byomailer:doctor');

        $checks = collect($json['data']['checks'])->keyBy('id');
        $this->assertSame(self::CHECK_IDS, $checks->keys()->all());

        foreach ($checks as $check) {
            $this->assertSame(['id', 'status', 'message', 'fix'], array_slice(array_keys($check), 0, 4));
            $this->assertContains($check['status'], ['ok', 'warn', 'fail']);
            $this->assertSame($check['status'] !== 'ok', $check['fix'] !== '', "{$check['id']} needs a fix only when it is not ok.");
        }

        foreach (self::SES_CHECKS as $id) {
            $this->assertSame('warn', $checks[$id]['status'], $id);
        }

        $this->assertSame(0, $json['data']['summary']['fail'], json_encode($checks->where('status', 'fail')->values()));
        $this->assertSame(count(self::CHECK_IDS), array_sum($json['data']['summary']));
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('/settings/email-credentials', $checks['ses_credentials']['fix']);
        $this->assertStringContainsString('SES_EVENTS_SQS_QUEUE_URL', $checks['ses_events']['fix']);
    }

    public function test_a_complete_ses_setup_passes_every_ses_check(): void
    {
        $owner = User::factory()->owner()->create();
        EmailCredential::factory()->create([
            'user_id' => $owner->id,
            'client_id' => $owner->client_id,
            'region' => 'eu-west-1',
            'production_access_enabled' => true,
            'sending_enabled' => true,
            'max_24_hour_send' => 50000,
            'quota_fetched_at' => now(),
        ]);
        EmailIdentity::factory()->create(['user_id' => $owner->id, 'client_id' => $owner->client_id]);
        $this->configureSqs();

        [, $json] = $this->runJson('byomailer:doctor');

        $checks = collect($json['data']['checks'])->keyBy('id');

        foreach (self::SES_CHECKS as $id) {
            $this->assertSame('ok', $checks[$id]['status'], "{$id}: {$checks[$id]['message']}");
        }
    }

    public function test_the_sandbox_a_bad_region_and_a_missing_identity_are_warnings(): void
    {
        $owner = User::factory()->owner()->create();
        EmailCredential::factory()->create([
            'user_id' => $owner->id,
            'client_id' => $owner->client_id,
            'region' => 'mars-1',
            'production_access_enabled' => false,
            'quota_fetched_at' => now(),
        ]);
        EmailIdentity::factory()->pending()->create(['user_id' => $owner->id, 'client_id' => $owner->client_id]);

        [$exit, $json] = $this->runJson('byomailer:doctor');

        $checks = collect($json['data']['checks'])->keyBy('id');
        $this->assertSame('ok', $checks['ses_credentials']['status']);
        $this->assertSame('warn', $checks['ses_region']['status']);
        $this->assertStringContainsString('mars-1', $checks['ses_region']['message']);
        $this->assertSame('warn', $checks['ses_quota']['status']);
        $this->assertStringContainsString('sandbox', $checks['ses_quota']['message']);
        $this->assertSame('warn', $checks['ses_identity']['status']);
        $this->assertSame(0, $exit);
    }

    public function test_a_quota_that_was_never_read_names_the_fetch_command(): void
    {
        $owner = User::factory()->owner()->create();
        EmailCredential::factory()->create(['user_id' => $owner->id, 'client_id' => $owner->client_id, 'quota_fetched_at' => null]);

        [, $json] = $this->runJson('byomailer:doctor');

        $check = collect($json['data']['checks'])->firstWhere('id', 'ses_quota');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('php artisan ses:fetch-quota', $check['fix']);
    }

    public function test_the_doctor_never_prints_a_secret(): void
    {
        $owner = User::factory()->owner()->create();
        $credential = EmailCredential::factory()->create(['user_id' => $owner->id, 'client_id' => $owner->client_id]);
        $this->configureSqs();

        [, , $raw] = $this->runJson('byomailer:doctor');

        $this->assertStringNotContainsString($credential->access_key, $raw);
        $this->assertStringNotContainsString($credential->secret_key, $raw);
        $this->assertStringNotContainsString('sqs-secret-value', $raw);
        $this->assertStringNotContainsString((string) config('app.key'), $raw);
    }

    public function test_fresh_heartbeats_pass_and_a_missing_app_key_fails(): void
    {
        config(['queue.default' => 'database']);
        Heartbeats::beat(Heartbeats::QUEUE);
        Heartbeats::beat(Heartbeats::SCHEDULER);

        [, $json] = $this->runJson('byomailer:doctor');
        $checks = collect($json['data']['checks'])->keyBy('id');
        $this->assertSame('ok', $checks['queue_heartbeat']['status']);
        $this->assertSame('ok', $checks['scheduler_heartbeat']['status']);

        config(['app.key' => '']);
        [$exit, $json] = $this->runJson('byomailer:doctor');

        $this->assertSame(1, $exit);
        $this->assertSame(['app_key'], array_column($json['errors'], 'code'));
    }

    public function test_fix_with_json_writes_one_json_object_and_repairs_the_storage_link(): void
    {
        // A public folder of its own, so that parallel tests keep the real link.
        $public = sys_get_temp_dir().'/byomailer-doctor-'.uniqid();
        mkdir($public);
        $previousPublic = public_path();
        $this->app->usePublicPath($public);
        config(['filesystems.links' => ["{$public}/storage" => storage_path('app/public')]]);

        try {
            [, $json] = $this->runJson('byomailer:doctor', ['--fix' => true]);

            $check = collect($json['data']['checks'])->firstWhere('id', 'storage_link');
            $this->assertSame('ok', $check['status']);
            $this->assertTrue($check['fixed']);
            $this->assertTrue(is_link("{$public}/storage"));
        } finally {
            $this->app->usePublicPath($previousPublic);
            @unlink("{$public}/storage");
            @rmdir($public);
        }
    }

    private function configureSqs(): void
    {
        config(['services.sqs_ses_events' => [
            'key' => 'AKIAEXAMPLEEXAMPLE00',
            'secret' => 'sqs-secret-value',
            'region' => 'eu-west-1',
            'queue_url' => 'https://sqs.eu-west-1.amazonaws.com/111111111111/byomailer-ses-events',
            'queue_arn' => 'arn:aws:sqs:eu-west-1:111111111111:byomailer-ses-events',
        ]]);
    }
}
