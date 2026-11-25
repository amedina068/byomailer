<?php

namespace App\Listeners;

use App\Events\ContactClickedLink;
use App\Listeners\Concerns\EnrollsVisualAutomations;
use App\Support\AutomationDefinition;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The engine enrolls a contact one time for each automation. A second click
 * on the same link does not start the workflow again. The listener sets the
 * tenant of the contact itself, the same as RunAutomationsForClickedLink.
 */
class EnrollVisualAutomationsForClickedLink implements ShouldQueue
{
    use EnrollsVisualAutomations;

    public function handle(ContactClickedLink $event): void
    {
        Tenant::run($event->contact->client_id, fn () => $this->enroll(
            $event->contact,
            AutomationDefinition::LINK_CLICKED,
            [$event->linkName],
        ));
    }
}
