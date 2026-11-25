<?php

namespace App\Listeners;

use App\Events\ContactSequencesSynced;
use App\Listeners\Concerns\EnrollsVisualAutomations;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollVisualAutomationsForSyncedSequences implements ShouldQueue
{
    use EnrollsVisualAutomations;

    public function handle(ContactSequencesSynced $event): void
    {
        $this->enroll($event->contact, 'added_to_sequence', $event->changes['attached'] ?? []);
    }
}
