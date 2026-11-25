<?php

namespace App\Listeners;

use App\Events\ContactSequenceFinished;
use App\Models\Automation;
use App\Services\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunAutomationsForFinishedSequence implements ShouldQueue
{
    public function __construct(private AutomationRunner $automationRunner) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    public function handle(ContactSequenceFinished $event): void
    {
        $automations = Automation::withoutGlobalScopes()
            ->where('client_id', $event->contact->client_id)
            ->live()
            ->where('type', 'simple')
            ->where('condition->type', 'sequence_finished')
            ->where('condition->value', $event->sequence->id)
            ->get();

        $automations->each(function (Automation $automation) use ($event): void {
            $this->automationRunner->runForContact($automation, $event->contact);
        });
    }
}
