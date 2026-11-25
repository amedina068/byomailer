<?php

namespace App\Listeners;

use App\Enums\FilterCategory;
use App\Enums\SequenceStatus;
use App\Events\ContactListsSynced;
use App\Services\ContactFilterService;
use App\Services\SequenceStepScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnforceSequenceFiltersOnListChange implements ShouldQueue
{
    public function __construct(
        private ContactFilterService $contactFilterService,
        private SequenceStepScheduler $sequenceStepScheduler
    ) {}

    public function viaQueue(): string
    {
        return 'sequences';
    }

    public function handle(ContactListsSynced $event): void
    {
        $contact = $event->contact;

        $sequences = $contact->sequences()
            ->where('status', SequenceStatus::Live)
            ->withAudienceRules()
            ->with(['segment' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($sequences as $sequence) {
            $filters = $sequence->effectiveFilters();

            $hasListFilter = collect($filters)->contains(fn (array $filter): bool => ($filter['category'] ?? '') === FilterCategory::Lists->value);

            if (! $hasListFilter) {
                continue;
            }

            if (! $this->contactFilterService->contactMatchesFilters($contact, $filters)) {
                $this->sequenceStepScheduler->exitSequence($contact, $sequence);
            }
        }
    }
}
