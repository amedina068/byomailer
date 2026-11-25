<?php

namespace App\Services;

use App\Enums\SequenceStatus;
use App\Models\Automation;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns sequences and sequence steps on and off. The dashboard and the MCP
 * server both change the status here, so both run the same checks.
 */
class SequenceStatusService
{
    public function __construct(
        private readonly EmailSendingGate $emailSendingGate,
        private readonly SendableContentCheck $sendableContentCheck,
    ) {}

    /**
     * The reasons that stop the sequence from going live. Empty when it can go live.
     *
     * @return list<string>
     */
    public function sequenceActivationProblems(User $user): array
    {
        $decision = $this->emailSendingGate->evaluate($user);

        return $decision->allowed ? [] : [(string) $decision->message];
    }

    /**
     * The reasons that stop the step from going live. Empty when it can go live.
     *
     * @return list<string>
     */
    public function stepActivationProblems(User $user, SequenceStep $step): array
    {
        $problems = $this->sequenceActivationProblems($user);

        if ($problems !== []) {
            return $problems;
        }

        return array_values($this->sendableContentCheck->problems($step->message));
    }

    /**
     * Compliance warnings for a step that goes live. They never block it.
     *
     * @return list<string>
     */
    public function stepWarnings(SequenceStep $step): array
    {
        return array_values($this->sendableContentCheck->warnings($step->message));
    }

    /**
     * Compliance warnings for a sequence that goes live: each live step
     * without an unsubscribe link, then the postal address. They never block it.
     *
     * @return list<string>
     */
    public function sequenceWarnings(Sequence $sequence): array
    {
        $warnings = $sequence->steps()
            ->where('status', SequenceStatus::Live)
            ->with('message')
            ->get()
            ->filter(fn (SequenceStep $step): bool => array_key_exists('unsubscribe', $this->sendableContentCheck->warnings($step->message)))
            ->map(fn (SequenceStep $step): string => "Step #{$step->id}: ".SendableContentCheck::UNSUBSCRIBE_WARNING)
            ->values()
            ->all();

        return [...$warnings, ...array_values($this->sendableContentCheck->postalAddressWarnings($sequence->client_id))];
    }

    /**
     * @throws ValidationException
     */
    public function setSequenceStatus(User $user, Sequence $sequence, SequenceStatus $status): void
    {
        if ($status === SequenceStatus::Live) {
            $this->throwWhenBlocked($this->sequenceActivationProblems($user));
        }

        $sequence->update(['status' => $status]);
    }

    /**
     * Lock the step, check its current content, then write the status. An
     * edit of the step locks the same row, so the check and the write see
     * the same content.
     *
     * @throws ValidationException
     */
    public function setStepStatus(User $user, SequenceStep $step, SequenceStatus $status): void
    {
        DB::transaction(function () use ($user, $step, $status): void {
            $locked = SequenceStep::query()->lockForUpdate()->findOrFail($step->id);

            if ($status === SequenceStatus::Live) {
                $this->throwWhenBlocked($this->stepActivationProblems($user, $locked));
            }

            $locked->update(['status' => $status]);
        });

        $step->setAttribute('status', $status)->syncOriginalAttribute('status');
    }

    /**
     * Count the contacts and mails that a status change of the sequence touches.
     *
     * @return array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int, live_steps: int, total_steps: int, live_automation_ids: list<int>}
     */
    public function sequenceImpact(Sequence $sequence): array
    {
        $stepIds = $sequence->steps()->pluck('id')->all();
        $liveSteps = $sequence->steps()->where('status', SequenceStatus::Live)->count();

        return [
            'enrolled_contacts' => $sequence->contacts()->wherePivotNull('completed_at')->count(),
            ...$this->pendingMailCounts($stepIds),
            'live_steps' => $liveSteps,
            'total_steps' => count($stepIds),
            'live_automation_ids' => $this->liveAutomationIdsAddingTo($sequence),
        ];
    }

    /**
     * Count the contacts and mails that a status change of the step touches.
     *
     * @return array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int}
     */
    public function stepImpact(Sequence $sequence, SequenceStep $step): array
    {
        return [
            'enrolled_contacts' => $sequence->contacts()->wherePivotNull('completed_at')->count(),
            ...$this->pendingMailCounts([$step->id]),
        ];
    }

    /**
     * @param  list<int>  $stepIds
     * @return array{pending_mails: int, pending_mails_due_now: int}
     */
    private function pendingMailCounts(array $stepIds): array
    {
        if ($stepIds === []) {
            return ['pending_mails' => 0, 'pending_mails_due_now' => 0];
        }

        $pending = fn () => ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->where('status', 'pending')
            ->whereNull('sent_at');

        return [
            'pending_mails' => $pending()->count(),
            'pending_mails_due_now' => $pending()->where('scheduled_at', '<=', now())->count(),
        ];
    }

    /**
     * @return list<int>
     */
    private function liveAutomationIdsAddingTo(Sequence $sequence): array
    {
        $automationStatusService = app(AutomationStatusService::class);

        return Automation::query()
            ->where('client_id', $sequence->client_id)
            ->live()
            ->get()
            ->filter(fn (Automation $automation): bool => in_array($sequence->id, $automationStatusService->sequenceIds($automation), true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $problems
     *
     * @throws ValidationException
     */
    private function throwWhenBlocked(array $problems): void
    {
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'status' => implode(' ', $problems),
            ]);
        }
    }
}
