<?php

namespace App\Listeners;

use App\Events\ContactListsSynced;
use App\Listeners\Concerns\EnrollsVisualAutomations;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollVisualAutomationsForSyncedLists implements ShouldQueue
{
    use EnrollsVisualAutomations;

    public function handle(ContactListsSynced $event): void
    {
        $this->enroll($event->contact, 'contact_added_to_list', $event->changes['attached'] ?? []);
        $this->enroll($event->contact, 'removed_from_list', $event->changes['detached'] ?? []);
    }
}
