<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Docker image reads lists and defaults that the app also holds. These
 * tests fail when the two sides are different.
 */
class DockerRuntimeFilesTest extends TestCase
{
    public function test_the_queue_worker_of_the_image_reads_each_queue_of_horizon(): void
    {
        $run = (string) file_get_contents(base_path('deploy/docker/s6-rc.d/byomailer-queue/run'));

        $this->assertSame(1, preg_match('/--queue=([a-z,-]+)\s/', $run, $match));

        $horizonQueues = collect(config('horizon.defaults'))->pluck('queue')->flatten()->unique()->sort()->values()->all();
        $workerQueues = collect(explode(',', $match[1]))->sort()->values()->all();

        $this->assertSame($horizonQueues, $workerQueues);
    }

    public function test_the_queue_worker_gives_a_job_the_longest_timeout_of_horizon(): void
    {
        $run = (string) file_get_contents(base_path('deploy/docker/s6-rc.d/byomailer-queue/run'));
        $longest = max(array_column(config('horizon.defaults'), 'timeout'));

        $this->assertStringContainsString("--timeout={$longest} ", $run);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $longest);
    }

    public function test_the_queue_service_runs_horizon_with_the_redis_queue(): void
    {
        $run = (string) file_get_contents(base_path('deploy/docker/s6-rc.d/byomailer-queue/run'));

        $this->assertMatchesRegularExpression('/redis\)\s+exec php artisan horizon/', $run);
    }

    /**
     * Without the SQS settings, the poller exits at once. The service must
     * then pause, not start the poller again in a loop.
     */
    public function test_the_ses_events_service_starts_the_poller_only_with_the_sqs_settings(): void
    {
        $run = (string) file_get_contents(base_path('deploy/docker/s6-rc.d/byomailer-ses-events/run'));

        foreach (['PLATFORM_AWS_ACCESS_KEY_ID', 'PLATFORM_AWS_SECRET_ACCESS_KEY', 'SES_EVENTS_SQS_QUEUE_URL'] as $name) {
            $this->assertStringContainsString($name, $run);
        }

        $this->assertStringContainsString('exec /command/s6-pause', $run);
        $this->assertStringContainsString('exec php artisan ses-events:poll', $run);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function imageDefaults(): array
    {
        return [
            ['DB_CONNECTION', 'sqlite'],
            ['DB_DATABASE', '/data/database.sqlite'],
            ['QUEUE_CONNECTION', 'database'],
            ['CACHE_STORE', 'file'],
            ['SESSION_DRIVER', 'file'],
            ['APP_KEY_FILE', '/data/.secrets/app_key'],
            ['LARAVEL_STORAGE_PATH', '/data/storage'],
            ['HEALTHCHECK_PATH', '/up'],
        ];
    }

    #[DataProvider('imageDefaults')]
    public function test_the_image_sets_the_default(string $name, string $value): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^\s+'.preg_quote("{$name}={$value}", '/').'\s/m', $dockerfile);
    }

    public function test_the_image_has_the_clients_that_load_a_schema_dump(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^\s+sqlite3\s/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^\s+mariadb-client-core\s/m', $dockerfile);
    }

    public function test_the_image_checks_its_health_on_the_up_route(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^HEALTHCHECK .*--start-period=\d+s/m', $dockerfile);
        $this->assertStringContainsString('${HEALTHCHECK_PATH}', $dockerfile);
    }

    public function test_the_data_script_writes_the_key_file_that_the_image_reads(): void
    {
        $script = (string) file_get_contents(base_path('deploy/docker/entrypoint.d/05-byomailer-data.sh'));

        $this->assertStringContainsString('${APP_KEY_FILE:-$data_dir/.secrets/app_key}', $script);
        $this->assertStringContainsString('umask 077', $script);
    }

    /**
     * The GeoIP database has a license that does not allow redistribution.
     * The scheduler downloads it when MAXMIND_LICENSE_KEY is set.
     */
    public function test_the_image_does_not_include_a_geoip_database(): void
    {
        $this->assertMatchesRegularExpression('/^\*\.mmdb$/m', (string) file_get_contents(base_path('.dockerignore')));
    }
}
