<?php

namespace App\Listeners;

use App\Events\ContactSequenceFinished;
use App\Listeners\Concerns\EnrollsVisualAutomations;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollVisualAutomationsForFinishedSequence implements ShouldQueue
{
    use EnrollsVisualAutomations;

    public function handle(ContactSequenceFinished $event): void
    {
        $this->enroll($event->contact, 'sequence_finished', [$event->sequence->id]);
    }
}
