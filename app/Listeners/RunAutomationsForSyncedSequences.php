<?php

namespace App\Listeners;

use App\Events\ContactSequencesSynced;
use App\Jobs\RunAutomationForContactJob;
use App\Models\Automation;

class RunAutomationsForSyncedSequences
{
    public function handle(ContactSequencesSynced $event): void
    {
        if ($event->automations->isEmpty()) {
            return;
        }

        $event->automations->each(function (Automation $automation) use ($event): void {
            RunAutomationForContactJob::dispatch($automation, $event->contact);
        });
    }
}
