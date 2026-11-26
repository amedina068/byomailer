<?php

namespace App\Jobs;

use App\Support\Instance\Heartbeats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Proves that a queue worker runs. The scheduler dispatches this job each
 * minute to the `default` queue, and `byomailer:status` reads the time that
 * it wrote.
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeats::beat(Heartbeats::QUEUE);
    }
}
