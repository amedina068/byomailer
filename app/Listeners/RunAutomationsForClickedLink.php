<?php

namespace App\Listeners;

use App\Events\ContactClickedLink;
use App\Models\Automation;
use App\Services\AutomationRunner;
use App\Support\AutomationDefinition;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The event fires after the commit of the SES event job. The listener sets
 * the tenant of the contact itself, so it does not depend on where the
 * commit happens.
 */
class RunAutomationsForClickedLink implements ShouldQueue
{
    public function __construct(private AutomationRunner $automationRunner) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    public function handle(ContactClickedLink $event): void
    {
        Tenant::run($event->contact->client_id, fn () => Automation::query()
            ->live()
            ->where('type', Automation::TYPE_SIMPLE)
            ->where('condition->type', AutomationDefinition::LINK_CLICKED)
            ->where('condition->value', $event->linkName)
            ->get()
            ->each(function (Automation $automation) use ($event): void {
                $this->automationRunner->runForContact($automation, $event->contact);
            }));
    }
}
