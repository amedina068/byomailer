<?php

namespace App\Support\Instance;

use App\Mcp\Servers\InstanceAdminServer;
use App\Models\User;
use App\Support\InstanceSetup;
use Composer\InstalledVersions;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One snapshot of the state of this instance.
 *
 * `byomailer:status --json` prints it, and the `get-instance-status` tool of
 * the instance-admin MCP server returns it. Every value comes from a fast
 * local read. The deeper checks are in `InstanceDoctor`.
 */
class InstanceStatus
{
    public function __construct(private Migrator $migrator, private SesStatus $ses) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $database = $this->database();
        $connected = $database['connected'];
        $queueBeat = $connected ? Heartbeats::last(Heartbeats::QUEUE) : null;
        $schedulerBeat = $connected ? Heartbeats::last(Heartbeats::SCHEDULER) : null;
        $queueConnection = (string) config('queue.default');

        return [
            'version' => self::version(),
            'app_url' => (string) config('app.url'),
            'environment' => (string) config('app.env'),
            'database' => $database,
            'queue' => [
                'connection' => $queueConnection,
                'worker' => self::worker($queueConnection),
                'heartbeat_at' => $queueBeat?->toIso8601String(),
                // The sync driver runs each job inside the request. No worker
                // exists, so no beat is necessary.
                'healthy' => $queueConnection === 'sync' || Heartbeats::isFresh($queueBeat),
            ],
            'scheduler' => [
                'last_run_at' => $schedulerBeat?->toIso8601String(),
                'healthy' => Heartbeats::isFresh($schedulerBeat),
            ],
            'storage_link' => file_exists(public_path('storage')),
            'mail_driver' => (string) config('mail.default'),
            'cache_driver' => (string) config('cache.default'),
            'mcp' => [
                'enabled' => true,
                'url' => McpConnect::url(),
                'admin_stdio_command' => 'php artisan mcp:start '.InstanceAdminServer::HANDLE,
                'admin_http_enabled' => (bool) config('mcp.admin_enabled'),
            ],
            'ses' => $connected ? $this->sesSummary() : null,
            'owner_exists' => $connected && $this->ownerExists(),
            'setup_url' => $connected && $this->setupNeeded() ? InstanceSetup::url() : null,
        ];
    }

    /**
     * The `APP_VERSION` of the image, or the version that Composer knows.
     */
    public static function version(): string
    {
        $configured = config('app.version');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) InstalledVersions::getRootPackage()['pretty_version'];
    }

    /**
     * The process that works the queue. The Redis queue uses Horizon. The
     * other queues use `queue:work`.
     */
    public static function worker(string $connection): string
    {
        return match ($connection) {
            'sync' => 'none',
            'redis' => 'horizon',
            default => 'queue:work',
        };
    }

    /**
     * @return array{driver: string, connected: bool, pending_migrations: int|null, error: string|null}
     */
    public function database(): array
    {
        $driver = (string) DB::connection()->getDriverName();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            return ['driver' => $driver, 'connected' => false, 'pending_migrations' => null, 'error' => class_basename($exception).': '.$this->firstLine($exception->getMessage())];
        }

        return ['driver' => $driver, 'connected' => true, 'pending_migrations' => $this->pendingMigrations(), 'error' => null];
    }

    public function pendingMigrations(): ?int
    {
        try {
            $files = $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);

            if (! $this->migrator->repositoryExists()) {
                return count($files);
            }

            return count(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int|bool>|null
     */
    private function sesSummary(): ?array
    {
        try {
            return $this->ses->summary();
        } catch (Throwable) {
            return null;
        }
    }

    private function ownerExists(): bool
    {
        try {
            return User::query()->where('is_owner', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function setupNeeded(): bool
    {
        try {
            return InstanceSetup::isNeeded();
        } catch (Throwable) {
            return false;
        }
    }

    private function firstLine(string $message): string
    {
        return strtok($message, "\n") ?: $message;
    }
}
