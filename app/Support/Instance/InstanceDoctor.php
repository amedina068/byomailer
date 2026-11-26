<?php

namespace App\Support\Instance;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * The health checks of `byomailer:doctor` and of the `run-doctor` tool.
 *
 * Each check returns `{id, status, message, fix}` (`DoctorCheck`). The SES
 * checks are in `SesChecks`.
 *
 * `--fix` runs only the fixes in `SAFE_FIXES`. Each one creates a file or a
 * link that is missing, and none of them changes data. The doctor never runs
 * migrations and never changes the environment.
 *
 * @phpstan-type DoctorResult array{id: string, status: string, message: string, fix: string}
 */
class InstanceDoctor
{
    /** The checks that `--fix` can repair. */
    public const SAFE_FIXES = ['storage_link'];

    public function __construct(
        private InstanceStatus $status,
        private SesChecks $ses,
        private Kernel $artisan,
    ) {}

    /**
     * @return list<array{id: string, status: string, message: string, fix: string, fixed?: bool}>
     */
    public function run(bool $fix = false): array
    {
        $checks = [];

        foreach ($this->checks() as $id => $check) {
            $result = $check();

            if ($fix && $result['status'] !== DoctorCheck::OK && in_array($id, self::SAFE_FIXES, true)) {
                $this->repair($id);
                $result = $check();
                $result['fixed'] = $result['status'] === DoctorCheck::OK;
            }

            $checks[] = $result;
        }

        if (! $this->status->database()['connected']) {
            return $checks;
        }

        return [...$checks, ...$this->sesChecks(), $this->geoip()];
    }

    /**
     * @param  list<array{status: string}>  $checks
     * @return array{ok: int, warn: int, fail: int}
     */
    public static function summary(array $checks): array
    {
        $summary = [DoctorCheck::OK => 0, DoctorCheck::WARN => 0, DoctorCheck::FAIL => 0];

        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return $summary;
    }

    /**
     * @return array<string, Closure(): array{id: string, status: string, message: string, fix: string}>
     */
    private function checks(): array
    {
        return [
            'app_key' => fn () => $this->appKey(),
            'database' => fn () => $this->database(),
            'migrations' => fn () => $this->migrations(),
            'storage_writable' => fn () => $this->storageWritable(),
            'storage_link' => fn () => $this->storageLink(),
            'queue_heartbeat' => fn () => $this->queueHeartbeat(),
            'scheduler_heartbeat' => fn () => $this->schedulerHeartbeat(),
            'cache_store' => fn () => $this->cacheStore(),
            'redis' => fn () => $this->redis(),
            'mail' => fn () => $this->mail(),
            'owner' => fn () => $this->owner(),
        ];
    }

    /** @return DoctorResult */
    private function appKey(): array
    {
        return config('app.key')
            ? DoctorCheck::ok('app_key', 'APP_KEY is set.')
            : DoctorCheck::fail('app_key', 'APP_KEY is empty. Sessions, the setup token and the stored AWS keys do not work.', 'Set APP_KEY in the environment. If the instance has stored AWS keys, use the old key, or each account must enter its keys again.');
    }

    /** @return DoctorResult */
    private function database(): array
    {
        $database = $this->status->database();

        return $database['connected']
            ? DoctorCheck::ok('database', "The {$database['driver']} database answers.")
            : DoctorCheck::fail('database', "The {$database['driver']} database does not answer: {$database['error']}", 'Make sure that the DB_* variables are correct and that the database server runs.');
    }

    /** @return DoctorResult */
    private function migrations(): array
    {
        $pending = $this->status->pendingMigrations();

        return match (true) {
            $pending === null => DoctorCheck::fail('migrations', 'The migration state cannot be read.', 'Correct the database check first.'),
            $pending === 0 => DoctorCheck::ok('migrations', 'All migrations ran.'),
            default => DoctorCheck::warn('migrations', "{$pending} migrations did not run.", 'Run php artisan migrate --force. The doctor does not run migrations.'),
        };
    }

    /** @return DoctorResult */
    private function storageWritable(): array
    {
        $paths = [storage_path('app'), storage_path('framework/cache'), storage_path('framework/views'), storage_path('logs')];
        $blocked = array_values(array_filter($paths, fn (string $path): bool => ! is_dir($path) || ! is_writable($path)));

        return $blocked === []
            ? DoctorCheck::ok('storage_writable', 'The storage folders are writable.')
            : DoctorCheck::fail('storage_writable', 'These folders are missing or not writable: '.implode(', ', $blocked).'.', 'Give the web server user write access to the storage folder.');
    }

    /** @return DoctorResult */
    private function storageLink(): array
    {
        return file_exists(public_path('storage'))
            ? DoctorCheck::ok('storage_link', 'public/storage links to the storage folder.')
            : DoctorCheck::warn('storage_link', 'public/storage does not exist. Uploaded images in emails do not load.', 'Run php artisan byomailer:doctor --fix, or run php artisan storage:link.');
    }

    /** @return DoctorResult */
    private function queueHeartbeat(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return DoctorCheck::ok('queue_heartbeat', 'The queue is sync. Each job runs inside its request, so no worker is necessary.');
        }

        $beat = Heartbeats::last(Heartbeats::QUEUE);

        if (Heartbeats::isFresh($beat)) {
            return DoctorCheck::ok('queue_heartbeat', 'A queue worker ran a job at '.$beat->toIso8601String().'.');
        }

        $start = InstanceStatus::worker($connection) === 'horizon' ? 'php artisan horizon' : 'php artisan queue:work';

        return DoctorCheck::warn(
            'queue_heartbeat',
            $beat ? 'The last queue heartbeat is from '.$beat->toIso8601String().'. No worker took a job since then.' : 'No queue worker ran the heartbeat job yet. Newsletters and sequences wait in the queue.',
            "Start the worker: {$start}. The scheduler must also run, because it sends the heartbeat job.",
        );
    }

    /** @return DoctorResult */
    private function schedulerHeartbeat(): array
    {
        $beat = Heartbeats::last(Heartbeats::SCHEDULER);

        if (Heartbeats::isFresh($beat)) {
            return DoctorCheck::ok('scheduler_heartbeat', 'The scheduler ran at '.$beat->toIso8601String().'.');
        }

        return DoctorCheck::warn(
            'scheduler_heartbeat',
            $beat ? 'The scheduler last ran at '.$beat->toIso8601String().'.' : 'The scheduler did not run yet. Scheduled newsletters and sequence steps do not send.',
            'Run php artisan schedule:work, or add a cron entry for php artisan schedule:run each minute.',
        );
    }

    /** @return DoctorResult */
    private function cacheStore(): array
    {
        return config('cache.default') === 'array'
            ? DoctorCheck::warn('cache_store', 'The cache store is array. Locks, rate limits and login links do not last between requests.', 'Set CACHE_STORE to database, file or redis.')
            : DoctorCheck::ok('cache_store', 'The cache store is '.config('cache.default').'.');
    }

    /** @return DoctorResult */
    private function redis(): array
    {
        $users = array_keys(array_filter([
            'queue' => config('queue.default') === 'redis',
            'cache' => config('cache.default') === 'redis',
            'session' => config('session.driver') === 'redis',
        ]));

        if ($users === []) {
            return DoctorCheck::ok('redis', 'The queue, the cache and the sessions do not use Redis.');
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            return DoctorCheck::fail('redis', 'Redis does not answer ('.class_basename($exception).'). The '.implode(', ', $users).' use Redis.', 'Make sure that REDIS_HOST, REDIS_PORT and REDIS_PASSWORD are correct and that Redis runs.');
        }

        return DoctorCheck::ok('redis', 'Redis answers. The '.implode(', ', $users).' use it.');
    }

    /** @return DoctorResult */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? DoctorCheck::warn('mail', "The app mailer is {$mailer}. Password reset and invitation emails do not leave this server. Newsletters do not use this mailer.", 'Set MAIL_MAILER=ses with the AWS_* variables, or set MAIL_MAILER=smtp with the MAIL_* variables.')
            : DoctorCheck::ok('mail', "The app mailer is {$mailer}.");
    }

    /** @return DoctorResult */
    private function owner(): array
    {
        if (! $this->status->database()['connected']) {
            return DoctorCheck::fail('owner', 'The owner cannot be read, because the database does not answer.', 'Correct the database check first.');
        }

        return User::query()->where('is_owner', true)->exists()
            ? DoctorCheck::ok('owner', 'An owner account exists.')
            : DoctorCheck::warn('owner', 'This instance has no owner yet.', 'Run php artisan byomailer:install --name="Your Name" --email=you@example.com --json, or open the setup URL.');
    }

    /**
     * @return list<array{id: string, status: string, message: string, fix: string}>
     */
    private function sesChecks(): array
    {
        try {
            return $this->ses->run();
        } catch (Throwable $exception) {
            return [DoctorCheck::warn('ses_credentials', 'The SES state cannot be read ('.class_basename($exception).').', 'Run php artisan migrate --force. Then run php artisan byomailer:doctor again.')];
        }
    }

    /** @return DoctorResult */
    private function geoip(): array
    {
        $path = (string) config('services.maxmind.database_path');

        return is_file($path)
            ? DoctorCheck::ok('geoip', 'The GeoIP database exists. Opens and clicks show a location.')
            : DoctorCheck::warn('geoip', 'The GeoIP database does not exist. Opens and clicks show no location.', 'Set MAXMIND_LICENSE_KEY (a free MaxMind account) and run php artisan geoip:download. The license does not let BYOMailer include the file.');
    }

    private function repair(string $id): void
    {
        try {
            match ($id) {
                'storage_link' => $this->callQuietly('storage:link'),
            };
        } catch (Throwable) {
            // The check runs again after the repair and reports the result.
        }
    }

    /**
     * Runs the Artisan command of a repair with no output.
     *
     * `Artisan::call()` replaces the output that `Artisan::output()` returns.
     * A caller that runs `byomailer:doctor --fix --json` in the same process
     * then reads the line of the repair command instead of the JSON object.
     * This method runs the command directly, so the JSON object stays the only
     * output.
     */
    private function callQuietly(string $command): int
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return $this->artisan->all()[$command]->run($input, new NullOutput);
    }
}
