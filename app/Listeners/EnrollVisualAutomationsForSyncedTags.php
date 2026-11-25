<?php

namespace App\Listeners;

use App\Events\ContactTagsSynced;
use App\Listeners\Concerns\EnrollsVisualAutomations;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollVisualAutomationsForSyncedTags implements ShouldQueue
{
    use EnrollsVisualAutomations;

    public function handle(ContactTagsSynced $event): void
    {
        $this->enroll($event->contact, 'tag_added', $event->changes['attached'] ?? []);
        $this->enroll($event->contact, 'removed_tag', $event->changes['detached'] ?? []);
    }
}
