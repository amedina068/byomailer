<?php

namespace App\Services;

use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SequenceAnalyticsService
{
    /**
     * @return array{contacts: int, completed: int, unsubscribed: int, clicked: int}
     */
    public function sequenceStats(Sequence $sequence): array
    {
        $stepIds = $this->stepIds($sequence->steps);

        return [
            'contacts' => (int) ($sequence->contacts_count),
            'completed' => $this->countCompletedContacts($sequence->id),
            'unsubscribed' => $this->countDistinctContacts($stepIds, 'unsubscribed_at'),
            'clicked' => $this->countDistinctContacts($stepIds, 'clicked_at'),
        ];
    }

    /**
     * Contacts who have finished the sequence: they received or skipped the
     * final step, or they left it when they stopped matching its filters.
     */
    public function countCompletedContacts(int $sequenceId): int
    {
        return (int) DB::table('contact_sequence')
            ->where('sequence_id', $sequenceId)
            ->whereNotNull('completed_at')
            ->count();
    }

    /**
     * @param  Collection<int, SequenceStep>  $steps
     * @return array<int, array{contacts: int, unsubscribed: int, clicked: int}>
     */
    public function stepStats(Collection $steps): array
    {
        $stepIds = $this->stepIds($steps);

        if ($stepIds->isEmpty()) {
            return [];
        }

        $stats = $this->aggregateStepStats($stepIds);
        $exclusiveCounts = $this->aggregateExclusiveContactCounts($steps, $stepIds);

        foreach ($exclusiveCounts as $stepId => $count) {
            if (isset($stats[$stepId])) {
                $stats[$stepId]['contacts'] = $count;
            }
        }

        return $stats;
    }

    /**
     * @return array{contacts: int, unsubscribed: int, clicked: int}
     */
    public function statsForStep(SequenceStep $step): array
    {
        $row = ContactMail::query()
            ->exclusiveToStep($step)
            ->selectRaw('COUNT(DISTINCT contact_id) as total_contacts')
            ->selectRaw('COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL THEN contact_id END) as unsubscribed_contacts')
            ->selectRaw('COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN contact_id END) as clicked_contacts')
            ->first();

        return [
            'contacts' => (int) ($row->total_contacts ?? 0),
            'unsubscribed' => (int) ($row->unsubscribed_contacts ?? 0),
            'clicked' => (int) ($row->clicked_contacts ?? 0),
        ];
    }

    /**
     * Count contacts per step, excluding contacts who have progressed to a later step.
     * A contact is only counted in the furthest step they have reached.
     *
     * @param  Collection<int, SequenceStep>  $steps  Ordered steps
     * @param  Collection<int, int>  $stepIds
     * @return array<int, int>
     */
    private function aggregateExclusiveContactCounts(Collection $steps, Collection $stepIds): array
    {
        $sequenceId = $steps->first()?->sequence_id;

        $completedContactIds = $sequenceId
            ? DB::table('contact_sequence')
                ->where('sequence_id', $sequenceId)
                ->whereNotNull('completed_at')
                ->pluck('contact_id')
                ->flip()
            : collect();

        $contactSteps = ContactMail::query()
            ->selectRaw('contact_id, mailable_id as step_id')
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->when($completedContactIds->isNotEmpty(), fn ($query) => $query
                ->whereNotIn('contact_id', $completedContactIds->keys()))
            ->distinct()
            ->get();

        /** @var array<int, int> $stepPosition step_id => ordered position */
        $stepPosition = $steps->values()
            ->mapWithKeys(fn (SequenceStep $step, int $index) => [$step->id => $index])
            ->all();

        /** @var array<int, int> $contactFurthestPosition contact_id => furthest step position */
        $contactFurthestPosition = [];

        foreach ($contactSteps as $row) {
            $position = $stepPosition[$row->step_id] ?? -1;
            $contactId = $row->contact_id;

            if (! array_key_exists($contactId, $contactFurthestPosition) || $position > $contactFurthestPosition[$contactId]) {
                $contactFurthestPosition[$contactId] = $position;
            }
        }

        /** @var array<int, int> $counts step_id => contact count */
        $counts = $stepIds->mapWithKeys(fn (int $id) => [$id => 0])->all();

        foreach ($contactSteps as $row) {
            $position = $stepPosition[$row->step_id] ?? -1;
            $contactId = $row->contact_id;

            if ($contactFurthestPosition[$contactId] === $position) {
                $counts[$row->step_id]++;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, int>  $stepIds
     * @return array<int, array{contacts: int, unsubscribed: int, clicked: int}>
     */
    private function aggregateStepStats(Collection $stepIds): array
    {
        if ($stepIds->isEmpty()) {
            return [];
        }

        $rows = ContactMail::query()
            ->selectRaw('mailable_id as step_id')
            ->selectRaw('COUNT(DISTINCT contact_id) as total_contacts')
            ->selectRaw('COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL THEN contact_id END) as unsubscribed_contacts')
            ->selectRaw('COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN contact_id END) as clicked_contacts')
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->groupBy('mailable_id')
            ->get()
            ->keyBy('step_id');

        return $stepIds->mapWithKeys(function (int $stepId) use ($rows): array {
            $data = $rows->get($stepId);

            return [
                $stepId => [
                    'contacts' => (int) ($data->total_contacts ?? 0),
                    'unsubscribed' => (int) ($data->unsubscribed_contacts ?? 0),
                    'clicked' => (int) ($data->clicked_contacts ?? 0),
                ],
            ];
        })->all();
    }

    /**
     * @param  Collection<int, int>  $stepIds
     */
    private function countDistinctContacts(Collection $stepIds, string $column): int
    {
        if ($stepIds->isEmpty()) {
            return 0;
        }

        return (int) ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->whereNotNull($column)
            ->distinct('contact_id')
            ->count('contact_id');
    }

    /**
     * @return array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}
     */
    public function mailMetricsForSequence(Sequence $sequence): array
    {
        return $this->mailMetricsForStepIds($this->stepIds($sequence->steps));
    }

    /**
     * @return array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}
     */
    public function mailMetricsForStep(SequenceStep $step): array
    {
        return $this->mailMetricsForStepIds(collect([$step->id]));
    }

    /**
     * @param  Collection<int, SequenceStep>  $steps
     * @return array<int, array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}>
     */
    public function mailMetricsForSteps(Collection $steps): array
    {
        $stepIds = $this->stepIds($steps);

        if ($stepIds->isEmpty()) {
            return [];
        }

        $rows = ContactMail::query()
            ->selectRaw('mailable_id as step_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL OR delivered_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', ['pending'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as skipped_count', ['skipped'])
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_count')
            ->selectRaw('SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed_count')
            ->selectRaw('SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END) as complained_count')
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->groupBy('mailable_id')
            ->get()
            ->keyBy('step_id');

        return $stepIds->mapWithKeys(function (int $stepId) use ($rows): array {
            return [$stepId => $this->buildMetrics($rows->get($stepId))];
        })->all();
    }

    /**
     * @param  Collection<int, int>  $stepIds
     * @return array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}
     */
    private function mailMetricsForStepIds(Collection $stepIds): array
    {
        if ($stepIds->isEmpty()) {
            return $this->buildMetrics(null);
        }

        $row = ContactMail::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL OR delivered_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', ['pending'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as skipped_count', ['skipped'])
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count')
            ->selectRaw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_count')
            ->selectRaw('SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed_count')
            ->selectRaw('SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END) as complained_count')
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->first();

        return $this->buildMetrics($row);
    }

    /**
     * @return array{total: int, sent: int, delivered: int, pending: int, skipped: int, opened: int, bounced: int, unsubscribed: int, complained: int, delivery_rate: float|null, open_rate: float|null, unsubscribe_rate: float|null}
     */
    private function buildMetrics(mixed $row): array
    {
        $total = (int) ($row->total ?? 0);
        $sent = (int) ($row->sent_count ?? 0);
        $delivered = (int) ($row->delivered_count ?? 0);
        $pending = (int) ($row->pending_count ?? 0);
        $skipped = (int) ($row->skipped_count ?? 0);
        $opened = (int) ($row->opened_count ?? 0);
        $bounced = (int) ($row->bounced_count ?? 0);
        $unsubscribed = (int) ($row->unsubscribed_count ?? 0);
        $complained = (int) ($row->complained_count ?? 0);

        return [
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'pending' => $pending,
            'skipped' => $skipped,
            'opened' => $opened,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'complained' => $complained,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : null,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : null,
            'unsubscribe_rate' => $sent > 0 ? round(($unsubscribed / $sent) * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, int>  $contactIds
     * @param  Collection<int, int>  $stepIds
     * @param  (Closure(Builder<ContactMailClick>): mixed)|null  $clickConstraint  Keeps only the clicks on one link.
     * @return array<int, array<int, array{url: string|null, clicked_at: string|null}>>
     */
    public function contactClicks(Collection $contactIds, Collection $stepIds, ?Closure $clickConstraint = null): array
    {
        if ($contactIds->isEmpty() || $stepIds->isEmpty()) {
            return [];
        }

        $contactMailIds = ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->whereIn('contact_id', $contactIds)
            ->pluck('id');

        if ($contactMailIds->isEmpty()) {
            return [];
        }

        return ContactMailClick::query()
            ->whereIn('contact_id', $contactIds)
            ->whereIn('contact_mail_id', $contactMailIds)
            ->when($clickConstraint, fn (Builder $query) => $clickConstraint($query))
            ->orderByDesc('clicked_at')
            ->get()
            ->groupBy('contact_id')
            ->map(function ($clicks) {
                return $clicks->map(function (ContactMailClick $click): array {
                    return [
                        'url' => $click->link,
                        'clicked_at' => optional($click->clicked_at)->toIso8601String(),
                    ];
                })->values()->all();
            })->all();
    }

    /**
     * @param  Collection<int, SequenceStep>  $steps
     * @return Collection<int, int>
     */
    private function stepIds(Collection $steps): Collection
    {
        return $steps->pluck('id')->filter()->values();
    }
}
