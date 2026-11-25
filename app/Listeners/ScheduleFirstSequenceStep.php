<?php

namespace App\Listeners;

use App\Events\ContactSequencesSynced;
use App\Services\SequenceStepScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;

class ScheduleFirstSequenceStep implements ShouldQueue
{
    public function __construct(private SequenceStepScheduler $scheduler) {}

    public function viaQueue(): string
    {
        return 'sequences';
    }

    public function handle(ContactSequencesSynced $event): void
    {
        $attached = $event->changes['attached'] ?? [];

        if (! $attached) {
            return;
        }

        $this->scheduler->scheduleInitialSteps($event->contact, $attached);
    }
}
