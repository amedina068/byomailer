<?php

namespace App\Listeners;

use App\Events\ContactAttributesChanged;
use App\Models\Automation;
use App\Services\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunAutomationsForChangedAttributes implements ShouldQueue
{
    public function __construct(private AutomationRunner $automationRunner) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    public function handle(ContactAttributesChanged $event): void
    {
        Automation::withoutGlobalScopes()
            ->where('client_id', $event->contact->client_id)
            ->live()
            ->where('type', Automation::TYPE_SIMPLE)
            ->where('condition->type', 'attribute_changed')
            ->whereIn('condition->attribute', array_keys($event->changes))
            ->get()
            ->filter(fn (Automation $automation): bool => $this->matches($automation, $event->changes))
            ->each(function (Automation $automation) use ($event): void {
                $this->automationRunner->runForContact($automation, $event->contact);
            });
    }

    /**
     * @param  array<string, array{old: ?string, new: ?string}>  $changes
     */
    private function matches(Automation $automation, array $changes): bool
    {
        $change = $changes[$automation->condition['attribute']] ?? null;

        if ($change === null) {
            return false;
        }

        return match ($automation->condition['operator'] ?? null) {
            'changed' => true,
            'changed_to' => $change['new'] === $automation->condition['value'] && $change['old'] !== $automation->condition['value'],
            default => false,
        };
    }
}
