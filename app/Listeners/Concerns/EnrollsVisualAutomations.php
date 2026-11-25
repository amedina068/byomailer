<?php

namespace App\Listeners\Concerns;

use App\Models\Contact;
use App\Models\VisualAutomation;
use App\Services\VisualAutomationEngine;

/**
 * Enrolls a contact in the live visual automations of its client whose
 * trigger matches. The listeners run on a queue, so the query sets the
 * client itself.
 */
trait EnrollsVisualAutomations
{
    public function __construct(private VisualAutomationEngine $engine) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    /**
     * @param  array<int, int|string>  $values  The ids of the lists, tags or sequences, or the link names.
     */
    protected function enroll(Contact $contact, string $triggerType, array $values): void
    {
        if ($values === []) {
            return;
        }

        VisualAutomation::withoutGlobalScopes()
            ->where('client_id', $contact->client_id)
            ->live()
            ->get()
            ->filter(fn (VisualAutomation $automation): bool => $automation->isTriggeredBy($triggerType, $values))
            ->each(fn (VisualAutomation $automation) => $this->engine->enroll($automation, $contact));
    }
}
