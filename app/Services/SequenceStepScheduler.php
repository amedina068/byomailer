<?php

namespace App\Services;

use App\Concerns\AppliesUtmParameters;
use App\Enums\DelayUnit;
use App\Enums\SequenceStatus;
use App\Events\ContactSequenceFinished;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SequenceStepScheduler
{
    use AppliesUtmParameters;

    public function __construct(private ContactFilterService $contactFilterService) {}

    /**
     * @param  array<int, int>  $sequenceIds
     */
    public function scheduleInitialSteps(Contact $contact, array $sequenceIds): void
    {
        if ($contact->isExcludedFromSending() || empty($sequenceIds)) {
            return;
        }

        $sequences = Sequence::withoutGlobalScopes()
            ->where('client_id', $contact->client_id)
            ->where('status', SequenceStatus::Live)
            ->whereIn('id', $sequenceIds)
            ->with([
                'user',
                'segment' => fn ($query) => $query->withoutGlobalScopes(),
                'steps' => fn ($query) => $query
                    ->where('status', SequenceStatus::Live)
                    ->orderBy(self::delayInHoursExpression())
                    ->orderBy('id')
                    ->with('message.emailIdentity'),
            ])
            ->get();

        $sequences->each(function (Sequence $sequence) use ($contact): void {
            $step = $sequence->steps->first();

            if (! $step) {
                return;
            }

            $filters = $sequence->effectiveFilters();

            if (! empty($filters) && ! $this->contactFilterService->contactMatchesFilters($contact, $filters)) {
                $this->exitSequence($contact, $sequence);

                return;
            }

            $this->scheduleStep($contact, $sequence, $step);
        });
    }

    public function scheduleNextStep(ContactMail $contactMail): void
    {
        if (! $contactMail->sequence_step_id || $contactMail->contact->isExcludedFromSending()) {
            return;
        }

        $currentStep = SequenceStep::query()
            ->where('id', $contactMail->sequence_step_id)
            ->with(['sequence.user', 'sequence.segment' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();

        if (! $currentStep || $currentStep->sequence?->status !== SequenceStatus::Live) {
            return;
        }

        $currentDelayInHours = $currentStep->delay_type->toHours($currentStep->delay_days);

        $nextStep = SequenceStep::query()
            ->where('sequence_id', $currentStep->sequence_id)
            ->where('status', SequenceStatus::Live)
            ->where(function ($query) use ($currentDelayInHours, $currentStep): void {
                $query->whereRaw(self::DELAY_IN_HOURS_SQL.' > ?', [$currentDelayInHours])
                    ->orWhere(function ($nested) use ($currentDelayInHours, $currentStep): void {
                        $nested->whereRaw(self::DELAY_IN_HOURS_SQL.' = ?', [$currentDelayInHours])
                            ->where('id', '>', $currentStep->id);
                    });
            })
            ->orderBy(self::delayInHoursExpression())
            ->orderBy('id')
            ->with('message.emailIdentity')
            ->first();

        $contact = $contactMail->contact;

        if (! $contact || ! $contact->sequences()->where('sequences.id', $currentStep->sequence_id)->exists()) {
            return;
        }

        $filters = $currentStep->sequence->effectiveFilters();

        if (! empty($filters) && ! $this->contactFilterService->contactMatchesFilters($contact, $filters)) {
            $this->exitSequence($contact, $currentStep->sequence);

            return;
        }

        if (! $nextStep) {
            $this->completeSequence($contact, $currentStep->sequence);

            return;
        }

        $this->scheduleStep($contact, $currentStep->sequence, $nextStep);
    }

    public function scheduleStep(Contact $contact, Sequence $sequence, SequenceStep $step): void
    {
        if ($contact->isExcludedFromSending() || $sequence->status !== SequenceStatus::Live || $step->status !== SequenceStatus::Live) {
            return;
        }

        $exists = ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->exists();

        if ($exists) {
            return;
        }

        $message = $step->message;

        if (! $message) {
            return;
        }

        try {
            $contactMail = ContactMail::create([
                'uuid' => (string) Str::uuid(),
                'contact_id' => $contact->id,
                'mailable_type' => SequenceStep::class,
                'mailable_id' => $step->id,
                'message_id' => $message->id,
                'subject' => $message->subject,
                'from_email' => $message->emailIdentity->email,
                'from_name' => $message->emailIdentity->name,
                'scheduled_at' => match ($step->delay_type) {
                    DelayUnit::Hours => now()->addHours((int) $step->delay_days),
                    DelayUnit::Days => now()->addDays((int) $step->delay_days),
                },
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }
    }

    public function rescheduleAfterStepDeletion(SequenceStep $deletedStep, SequenceStep $nextStep): void
    {
        $sequence = $deletedStep->sequence;

        $contactIds = ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $deletedStep->id)
            ->where(fn ($query) => $query->whereNotNull('delivered_at')->orWhere('status', 'skipped'))
            ->pluck('contact_id');

        if ($contactIds->isEmpty()) {
            return;
        }

        $alreadyScheduledContactIds = ContactMail::query()
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $nextStep->id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id');

        $completedContactIds = DB::table('contact_sequence')
            ->where('sequence_id', $sequence->id)
            ->whereIn('contact_id', $contactIds)
            ->whereNotNull('completed_at')
            ->pluck('contact_id');

        $contactsToSchedule = Contact::query()
            ->whereIn('id', $contactIds->diff($alreadyScheduledContactIds)->diff($completedContactIds))
            ->mailable()
            ->get();

        $contactsToSchedule->each(function (Contact $contact) use ($sequence, $nextStep): void {
            $this->scheduleStep($contact, $sequence, $nextStep);
        });
    }

    /**
     * A contact who no longer matches the sequence filters leaves the
     * sequence. The pending mails stay as `skipped` rows, so the dashboard
     * shows them, and the contact finishes the sequence. The finish runs the
     * "sequence finished" automations, for example the removal of a tag that
     * the "added to sequence" automations added.
     */
    public function exitSequence(Contact $contact, Sequence $sequence): void
    {
        DB::transaction(function () use ($contact, $sequence): void {
            $this->cancelPendingSequenceMails($contact, $sequence->id);
            $this->completeSequence($contact, $sequence);
        });
    }

    /**
     * Marks the contact completed on the pivot and dispatches
     * `ContactSequenceFinished`. A contact that already completed the
     * sequence, or that is not in it, gets no second event.
     */
    public function completeSequence(Contact $contact, Sequence $sequence): void
    {
        $updated = $contact->sequences()
            ->wherePivotNull('completed_at')
            ->updateExistingPivot($sequence->id, ['completed_at' => now()]);

        if ($updated === 0) {
            return;
        }

        ContactSequenceFinished::dispatch($contact, $sequence);
    }

    public function cancelPendingSequenceMails(Contact $contact, int $sequenceId): void
    {
        $stepIds = SequenceStep::query()
            ->where('sequence_id', $sequenceId)
            ->pluck('id');

        ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->whereIn('mailable_id', $stepIds)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->update([
                'status' => 'skipped',
                'failure_reason' => self::FILTER_EXIT_REASON,
                'reserved_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * The reason on a mail that was skipped because the contact left the
     * sequence when it stopped matching the sequence filters.
     */
    public const FILTER_EXIT_REASON = 'sequence_filter_mismatch';

    public const DELAY_IN_HOURS_SQL = "CASE WHEN delay_type = '".DelayUnit::Hours->value."' THEN delay_days ELSE delay_days * 24 END";

    /**
     * SQL expression that normalizes delay to hours for consistent ordering.
     */
    public static function delayInHoursExpression(): Expression
    {
        return DB::raw(self::DELAY_IN_HOURS_SQL);
    }

    /**
     * Step ids within the same sequence that come after the given step in
     * chronological order (by delay, ties broken by id).
     *
     * @return Collection<int, int>
     */
    public static function laterStepIdsFor(SequenceStep $step): Collection
    {
        $currentDelayInHours = $step->delay_type->toHours($step->delay_days);

        return SequenceStep::query()
            ->where('sequence_id', $step->sequence_id)
            ->where(function ($query) use ($currentDelayInHours, $step): void {
                $query->whereRaw(self::DELAY_IN_HOURS_SQL.' > ?', [$currentDelayInHours])
                    ->orWhere(function ($nested) use ($currentDelayInHours, $step): void {
                        $nested->whereRaw(self::DELAY_IN_HOURS_SQL.' = ?', [$currentDelayInHours])
                            ->where('id', '>', $step->id);
                    });
            })
            ->pluck('id');
    }

    public static function addDefaultUtmParametersToHtml(string $content, SequenceStep $step): string
    {
        return self::addUtmParametersToHtml($content, self::defaultUtmParameters($step));
    }

    public static function addDefaultUtmParametersToText(string $content, SequenceStep $step): string
    {
        return self::addUtmParametersToPlainText($content, self::defaultUtmParameters($step));
    }

    /**
     * @return array<string, string>
     */
    public static function defaultUtmParameters(SequenceStep $step): array
    {
        $subject = Str::slug(trim($step->message?->subject ?? '')) ?: 'sequence-step';
        $sequenceName = Str::slug(trim($step->sequence?->name ?? '')) ?: 'sequence';

        return [
            'utm_medium' => 'mail',
            'utm_source' => $subject,
            'utm_campaign' => $sequenceName,
        ];
    }
}
