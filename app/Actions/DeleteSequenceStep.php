<?php

namespace App\Actions;

use App\Enums\SequenceStatus;
use App\Models\ContactMail;
use App\Models\SequenceStep;
use App\Services\SequenceStepScheduler;
use Illuminate\Support\Facades\DB;

class DeleteSequenceStep
{
    public function __construct(private readonly SequenceStepScheduler $scheduler) {}

    /**
     * Delete a step, its message and its pending mails in one transaction.
     * Contacts who already got or skipped the step get the next live step.
     */
    public function handle(SequenceStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $step = SequenceStep::query()->lockForUpdate()->with(['message', 'sequence'])->findOrFail($step->id);

            $nextStep = $this->nextLiveStep($step);

            if ($nextStep) {
                $this->scheduler->rescheduleAfterStepDeletion($step, $nextStep);
            }

            ContactMail::query()
                ->where('mailable_type', SequenceStep::class)
                ->where('mailable_id', $step->id)
                ->where('status', 'pending')
                ->whereNull('sent_at')
                ->delete();

            $step->message?->delete();
            $step->delete();
        });
    }

    private function nextLiveStep(SequenceStep $step): ?SequenceStep
    {
        $delayInHours = $step->delay_type->toHours($step->delay_days);

        return SequenceStep::query()
            ->where('sequence_id', $step->sequence_id)
            ->where('status', SequenceStatus::Live)
            ->where('id', '!=', $step->id)
            ->where(function ($query) use ($delayInHours, $step): void {
                $query->whereRaw(SequenceStepScheduler::DELAY_IN_HOURS_SQL.' > ?', [$delayInHours])
                    ->orWhere(function ($nested) use ($delayInHours, $step): void {
                        $nested->whereRaw(SequenceStepScheduler::DELAY_IN_HOURS_SQL.' = ?', [$delayInHours])
                            ->where('id', '>', $step->id);
                    });
            })
            ->orderBy(SequenceStepScheduler::delayInHoursExpression())
            ->orderBy('id')
            ->first();
    }
}
