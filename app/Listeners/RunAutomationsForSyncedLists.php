<?php

namespace App\Listeners;

use App\Events\ContactListsSynced;
use App\Models\Automation;
use App\Services\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunAutomationsForSyncedLists implements ShouldQueue
{
    public function __construct(private AutomationRunner $automationRunner) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    public function handle(ContactListsSynced $event): void
    {
        $attached = $event->changes['attached'] ?? [];
        $detached = $event->changes['detached'] ?? [];

        if ($attached) {
            $automations = Automation::withoutGlobalScopes()
                ->where('client_id', $event->contact->client_id)
                ->live()
                ->where('type', 'simple')
                ->where('condition->type', 'contact_added_to_list')
                ->whereIn('condition->value', $attached)
                ->get();

            $automations->each(function (Automation $automation) use ($event): void {
                $this->automationRunner->runForContact($automation, $event->contact);
            });
        }

        if ($detached) {
            $automations = Automation::withoutGlobalScopes()
                ->where('client_id', $event->contact->client_id)
                ->live()
                ->where('type', 'simple')
                ->where('condition->type', 'removed_from_list')
                ->whereIn('condition->value', $detached)
                ->get();

            $automations->each(function (Automation $automation) use ($event): void {
                $this->automationRunner->runForContact($automation, $event->contact);
            });
        }
    }
}
