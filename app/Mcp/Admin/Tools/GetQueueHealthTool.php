<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\RedactsSecrets;
use App\Support\Instance\Heartbeats;
use App\Support\Instance\InstanceStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get-queue-health')]
#[Title('Get queue health')]
#[Description('Returns the queue connection, the worker (Horizon for Redis, queue:work for the other queues), the last worker heartbeat, the number of waiting jobs on each queue, the number of failed jobs and the 10 newest failed jobs with a redacted first line of the error.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetQueueHealthTool extends AdminTool
{
    use RedactsSecrets;

    public function handle(Request $request): Response
    {
        return $this->audited($request, function (): Response {
            $connection = (string) config('queue.default');
            $beat = Heartbeats::last(Heartbeats::QUEUE);

            return self::ok([
                'connection' => $connection,
                'worker' => InstanceStatus::worker($connection),
                'heartbeat_at' => $beat?->toIso8601String(),
                'healthy' => $connection === 'sync' || Heartbeats::isFresh($beat),
                'waiting' => $this->waiting($connection),
                'failed' => [
                    'count' => DB::table('failed_jobs')->count(),
                    'newest' => DB::table('failed_jobs')->orderByDesc('id')->limit(10)->get()
                        ->map(fn (object $job): array => [
                            'uuid' => $job->uuid,
                            'queue' => $job->queue,
                            'job' => json_decode((string) $job->payload, true)['displayName'] ?? null,
                            'failed_at' => (string) $job->failed_at,
                            'error' => $this->redact(strtok((string) $job->exception, "\n") ?: ''),
                        ])->values()->all(),
                ],
            ]);
        });
    }

    /**
     * The queue names come from the Horizon supervisors, so the list matches
     * the queues that the app uses.
     *
     * @return array<string, int|null>
     */
    private function waiting(string $connection): array
    {
        $queues = collect((array) config('horizon.defaults'))->pluck('queue')->flatten()->filter()->unique()->values()->all() ?: ['default'];
        $sizes = [];

        foreach ($queues as $queue) {
            try {
                $sizes[$queue] = $connection === 'sync' ? 0 : Queue::connection($connection)->size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        return $sizes;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
