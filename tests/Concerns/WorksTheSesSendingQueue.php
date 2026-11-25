<?php

namespace Tests\Concerns;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Runs SES send jobs through a real queue worker and the real
 * `RateLimitedWithRedis` middleware. Redis is a fake that refuses a set
 * number of send slots, so the middleware releases the job that many times.
 */
trait WorksTheSesSendingQueue
{
    /**
     * Refuse the next `$times` slots of the `ses-sending` limiter, then allow
     * every slot after them.
     */
    protected function refuseSesSendingSlots(int $times): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('eval')->andReturnUsing(function (string $script) use (&$times): array {
            // Newer Laravel 13 releases first run a read-only check
            // that returns [decaysAt, remaining]. Only the acquire script
            // (the one with HINCRBY) takes a slot, so only it is refused.
            if (! str_contains($script, 'HINCRBY')) {
                return [time() + 1, 1];
            }

            return [$times-- > 0 ? 0 : 1, time() + 1, 0];
        });

        Redis::shouldReceive('connection')->andReturn($connection);
    }

    /**
     * Process one job of the database queue, as a Horizon worker does. The
     * `--tries` value is the value of the Horizon supervisors.
     */
    protected function workOneSesSendingJob(string $queue): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 3,
            '--sleep' => 0,
            '--memory' => 4096,
        ])->assertSuccessful();
    }
}
