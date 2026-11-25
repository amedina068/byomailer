<?php

namespace App\Services;

use App\Enums\SequenceStatus;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationContactStep;
use App\Models\VisualAutomationEnrollment;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\LazyCollection;

class VisualAutomationEngine
{
    /**
     * Enroll a contact into a visual automation and start at the trigger's first child node.
     */
    public function enroll(VisualAutomation $automation, Contact $contact): ?VisualAutomationEnrollment
    {
        $existingEnrollment = $automation->enrollments()
            ->where('contact_id', $contact->id)
            ->first();

        if ($existingEnrollment) {
            return null;
        }

        try {
            $enrollment = $automation->enrollments()->create([
                'contact_id' => $contact->id,
                'status' => 'active',
                'enrolled_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $trigger = $automation->triggerNode();

        if (! $trigger) {
            return $enrollment;
        }

        $edges = $automation->outgoingEdges($trigger['id']);

        foreach ($edges as $edge) {
            $enrollment->steps()->create([
                'node_id' => $edge['target'],
                'status' => VisualAutomationContactStep::STATUS_PENDING,
            ]);
        }

        return $enrollment;
    }

    /**
     * Process all pending and ready-to-resume steps across all active enrollments.
     */
    public function processAllPending(): int
    {
        $processed = 0;

        foreach ($this->processableEnrollmentsQuery()->cursor() as $enrollment) {
            $enrollment->load([
                'visualAutomation' => fn ($q) => $q->withoutGlobalScopes(),
                'contact' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

            $processed += Tenant::run($enrollment->visualAutomation->client_id, fn (): int => $this->processEnrollment($enrollment));
        }

        return $processed;
    }

    /**
     * Get IDs of enrollments that have steps ready to process.
     *
     * @return LazyCollection<int, int>
     */
    public function findProcessableEnrollmentIds(): LazyCollection
    {
        return $this->processableEnrollmentsQuery()->pluck('id')->lazy();
    }

    /**
     * The enrollments of every client. The cron reads them with no tenant,
     * and each enrollment is then processed as the client of its automation.
     *
     * @return Builder<VisualAutomationEnrollment>
     */
    private function processableEnrollmentsQuery(): Builder
    {
        return VisualAutomationEnrollment::query()
            ->where('status', 'active')
            ->whereHas('visualAutomation', fn ($q) => $q->withoutGlobalScopes()->where('status', VisualAutomation::STATUS_LIVE))
            ->whereHas('steps', fn ($q) => $q
                ->where('status', VisualAutomationContactStep::STATUS_PENDING)
                ->orWhere(fn ($w) => $w
                    ->where('status', VisualAutomationContactStep::STATUS_WAITING)
                    ->where(fn ($ww) => $ww
                        ->where('wait_until', '<=', now())
                        ->orWhereNull('wait_until')
                    )
                )
            );
    }

    /**
     * Process steps for a single enrollment.
     */
    public function processEnrollment(VisualAutomationEnrollment $enrollment): int
    {
        if ($enrollment->status !== 'active') {
            return 0;
        }

        $enrollment->loadMissing(['visualAutomation', 'contact']);
        $automation = $enrollment->visualAutomation;
        $contact = $enrollment->contact;

        if (! $automation || ! $contact) {
            return 0;
        }

        if ($contact->unsubscribed_at) {
            $this->cancelEnrollment($enrollment);

            return 0;
        }

        $processed = 0;

        $readyDelays = $enrollment->steps()
            ->where('status', VisualAutomationContactStep::STATUS_WAITING)
            ->where('wait_until', '<=', now())
            ->get();

        foreach ($readyDelays as $step) {
            $step->update([
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            $this->advanceToNextNodes($step, $enrollment, $automation, $step->node_id);
            $processed++;
        }

        $waitingSequenceSteps = $enrollment->steps()
            ->where('status', VisualAutomationContactStep::STATUS_WAITING)
            ->whereNull('wait_until')
            ->get();

        foreach ($waitingSequenceSteps as $step) {
            $node = $automation->findNode($step->node_id);

            if (! $node) {
                $step->update(['status' => VisualAutomationContactStep::STATUS_SKIPPED]);
                $processed++;

                continue;
            }

            $value = $node['data']['value'] ?? null;
            if ($value !== null && $this->shouldAdvancePastSequence($contact, (int) $value)) {
                $step->update([
                    'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                    'processed_at' => now(),
                ]);
                $this->advanceToNextNodes($step, $enrollment, $automation, $node['id']);
                $processed++;
            }
        }

        // Phase 3: Process all pending steps
        $pendingSteps = $enrollment->steps()
            ->where('status', VisualAutomationContactStep::STATUS_PENDING)
            ->get();

        foreach ($pendingSteps as $step) {
            $contact->refresh();
            if ($contact->unsubscribed_at) {
                $this->cancelEnrollment($enrollment);

                return $processed;
            }

            $this->processStep($step, $enrollment);
            $processed++;
        }

        return $processed;
    }

    /**
     * Process a single step.
     */
    public function processStep(VisualAutomationContactStep $step, ?VisualAutomationEnrollment $enrollment = null): void
    {
        if (! $enrollment) {
            $enrollment = $step->enrollment()->with('visualAutomation', 'contact')->first();
        }

        if (! $enrollment || $enrollment->status !== 'active') {
            $step->update(['status' => VisualAutomationContactStep::STATUS_SKIPPED]);

            return;
        }

        $enrollment->loadMissing(['visualAutomation', 'contact']);
        $automation = $enrollment->visualAutomation;
        $contact = $enrollment->contact;

        if ($contact->unsubscribed_at) {
            $this->cancelEnrollment($enrollment);

            return;
        }

        $node = $automation->findNode($step->node_id);

        if (! $node) {
            $step->update([
                'status' => VisualAutomationContactStep::STATUS_SKIPPED,
                'processed_at' => now(),
            ]);

            return;
        }

        $nodeType = $node['type'] ?? '';

        match ($nodeType) {
            'action' => $this->processActionNode($step, $enrollment, $automation, $contact, $node),
            'delay' => $this->processDelayNode($step, $node),
            'condition' => $this->processConditionNode($step, $enrollment, $automation, $contact, $node),
            default => $step->update([
                'status' => VisualAutomationContactStep::STATUS_SKIPPED,
                'processed_at' => now(),
            ]),
        };
    }

    private function processActionNode(
        VisualAutomationContactStep $step,
        VisualAutomationEnrollment $enrollment,
        VisualAutomation $automation,
        Contact $contact,
        array $node,
    ): void {
        $data = $node['data'] ?? [];
        $actionType = $data['action_type'] ?? '';
        $value = $data['value'] ?? null;

        if ($value !== null) {
            match ($actionType) {
                'add_tag' => $contact->syncTags([$value], true),
                'remove_tag' => $contact->syncTags(
                    array_values(array_diff($contact->tags()->pluck('tags.id')->all(), [$value]))
                ),
                'add_list' => $contact->syncLists([$value], true),
                'remove_list' => $contact->syncLists(
                    array_values(array_diff($contact->lists()->pluck('lists.id')->all(), [$value]))
                ),
                'add_sequence' => $contact->syncSequences([$value], true),
                default => null,
            };
        }

        if ($actionType === 'unsubscribe_contact') {
            $contact->update(['unsubscribed_at' => now()]);

            $step->update([
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            $this->cancelEnrollment($enrollment);

            return;
        }

        // For add_sequence, wait until the sequence finishes before advancing
        if ($actionType === 'add_sequence' && $value !== null) {
            if ($this->shouldAdvancePastSequence($contact, (int) $value)) {
                $step->update([
                    'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                    'processed_at' => now(),
                ]);
                $this->advanceToNextNodes($step, $enrollment, $automation, $node['id']);
            } else {
                $step->update([
                    'status' => VisualAutomationContactStep::STATUS_WAITING,
                ]);
            }

            return;
        }

        $step->update([
            'status' => VisualAutomationContactStep::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $this->advanceToNextNodes($step, $enrollment, $automation, $node['id']);
    }

    /**
     * Determine if a contact should advance past an add_sequence step.
     *
     * Returns true if the sequence doesn't exist, has no steps, the contact
     * isn't actually in the sequence (filters rejected), or the contact has
     * already finished all steps.
     */
    private function shouldAdvancePastSequence(Contact $contact, int $sequenceId): bool
    {
        $sequence = Sequence::find($sequenceId);
        if (! $sequence) {
            return true;
        }

        // If the contact isn't actually in the sequence (filters rejected the enrollment),
        // don't leave the step stuck — advance past it
        if (! $contact->sequences()->where('sequences.id', $sequenceId)->exists()) {
            return true;
        }

        $liveStepIds = SequenceStep::query()
            ->where('sequence_id', $sequenceId)
            ->where('status', SequenceStatus::Live)
            ->pluck('id');

        if ($liveStepIds->isEmpty()) {
            return true;
        }

        // Check if there are any pending (unsent) mails for steps in this sequence
        $hasPendingMails = ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $liveStepIds)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->exists();

        if ($hasPendingMails) {
            return false;
        }

        // Check if the last step has been delivered or skipped by its step filters
        $lastStep = SequenceStep::query()
            ->where('sequence_id', $sequenceId)
            ->where('status', SequenceStatus::Live)
            ->orderBy(SequenceStepScheduler::delayInHoursExpression(), 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $lastStep) {
            return true;
        }

        return ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $lastStep->id)
            ->where(fn ($query) => $query->whereNotNull('delivered_at')->orWhere('status', 'skipped'))
            ->exists();
    }

    /**
     * Advance all WAITING add_sequence steps for a contact+sequence that have finished.
     */
    public function advanceWaitingSequenceSteps(Contact $contact, Sequence $sequence): void
    {
        $waitingSteps = VisualAutomationContactStep::query()
            ->where('status', VisualAutomationContactStep::STATUS_WAITING)
            ->whereNull('wait_until')
            ->whereHas('enrollment', function ($query) use ($contact) {
                $query->where('contact_id', $contact->id)
                    ->where('status', 'active')
                    ->whereHas('visualAutomation', fn ($q) => $q->where('status', VisualAutomation::STATUS_LIVE));
            })
            ->with(['enrollment.visualAutomation'])
            ->get();

        foreach ($waitingSteps as $step) {
            $enrollment = $step->enrollment;
            if (! $enrollment) {
                continue;
            }

            $automation = $enrollment->visualAutomation;
            $node = $automation->findNode($step->node_id);

            if (! $node) {
                continue;
            }

            $actionType = $node['data']['action_type'] ?? '';
            $value = $node['data']['value'] ?? null;

            if ($actionType !== 'add_sequence' || (int) $value !== $sequence->id) {
                continue;
            }

            $step->update([
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            $this->advanceToNextNodes($step, $enrollment, $automation, $node['id']);
        }
    }

    private function processDelayNode(
        VisualAutomationContactStep $step,
        array $node,
    ): void {
        $data = $node['data'] ?? [];
        $duration = (int) ($data['duration'] ?? 1);
        $unit = $data['unit'] ?? 'days';

        $waitUntil = match ($unit) {
            'minutes' => now()->addMinutes($duration),
            'hours' => now()->addHours($duration),
            'days' => now()->addDays($duration),
            default => now()->addDays($duration),
        };

        $step->update([
            'status' => VisualAutomationContactStep::STATUS_WAITING,
            'wait_until' => $waitUntil,
        ]);
    }

    private function processConditionNode(
        VisualAutomationContactStep $step,
        VisualAutomationEnrollment $enrollment,
        VisualAutomation $automation,
        Contact $contact,
        array $node,
    ): void {
        $data = $node['data'] ?? [];
        $conditionType = $data['condition_type'] ?? '';
        $value = $data['value'] ?? null;

        $result = $this->evaluateCondition($contact, $conditionType, $value);
        $branch = $result ? 'yes' : 'no';

        $step->update([
            'status' => VisualAutomationContactStep::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $edges = $automation->outgoingEdges($node['id'], $branch);

        foreach ($edges as $edge) {
            try {
                $enrollment->steps()->create([
                    'node_id' => $edge['target'],
                    'status' => VisualAutomationContactStep::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Step already created by a concurrent process — safe to skip
            }
        }

        if (empty($edges)) {
            $this->checkEnrollmentCompletion($enrollment);
        }
    }

    private function evaluateCondition(Contact $contact, string $conditionType, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return match ($conditionType) {
            'has_tag' => $contact->tags()->where('tags.id', $value)->exists(),
            'not_has_tag' => ! $contact->tags()->where('tags.id', $value)->exists(),
            'in_list' => $contact->lists()->where('lists.id', $value)->exists(),
            'not_in_list' => ! $contact->lists()->where('lists.id', $value)->exists(),
            'subscribed_to_topic' => $contact->topics()
                ->where('topics.id', $value)
                ->whereNull('contact_topic.unsubscribed_at')
                ->exists(),
            'not_subscribed_to_topic' => ! $contact->topics()
                ->where('topics.id', $value)
                ->whereNull('contact_topic.unsubscribed_at')
                ->exists(),
            'in_sequence' => $contact->sequences()->where('sequences.id', $value)->exists(),
            'not_in_sequence' => ! $contact->sequences()->where('sequences.id', $value)->exists(),
            default => false,
        };
    }

    private function advanceToNextNodes(
        VisualAutomationContactStep $step,
        VisualAutomationEnrollment $enrollment,
        VisualAutomation $automation,
        string $nodeId,
    ): void {
        $edges = $automation->outgoingEdges($nodeId);

        if (empty($edges)) {
            $this->checkEnrollmentCompletion($enrollment);

            return;
        }

        foreach ($edges as $edge) {
            try {
                $enrollment->steps()->create([
                    'node_id' => $edge['target'],
                    'status' => VisualAutomationContactStep::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Step already created by a concurrent process — safe to skip
            }
        }
    }

    private function checkEnrollmentCompletion(VisualAutomationEnrollment $enrollment): void
    {
        $hasActiveSteps = $enrollment->steps()
            ->whereIn('status', [
                VisualAutomationContactStep::STATUS_PENDING,
                VisualAutomationContactStep::STATUS_WAITING,
            ])
            ->exists();

        if (! $hasActiveSteps) {
            $enrollment->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Cancel all remaining steps and complete the enrollment.
     */
    private function cancelEnrollment(VisualAutomationEnrollment $enrollment): void
    {
        $enrollment->steps()
            ->whereIn('status', [
                VisualAutomationContactStep::STATUS_PENDING,
                VisualAutomationContactStep::STATUS_WAITING,
            ])
            ->update([
                'status' => VisualAutomationContactStep::STATUS_SKIPPED,
                'processed_at' => now(),
            ]);

        $enrollment->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
