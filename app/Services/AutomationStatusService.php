<?php

namespace App\Services;

use App\Models\Automation;
use App\Rules\SequenceMustBeLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switches automations on and off. The dashboard and the MCP server both
 * change the live flag here, so both run the same checks.
 */
class AutomationStatusService
{
    /**
     * The reasons that stop the automation from going live. Empty when it can go live.
     *
     * A live automation enrolls contacts only into live sequences. A sequence
     * that is not live drops the contact without an error, so every
     * "add_sequence" action must point to a live sequence first.
     *
     * @return list<string>
     */
    public function activationProblems(Automation $automation): array
    {
        if (! SequenceMustBeLive::allLive($this->sequenceIds($automation))) {
            return [SequenceMustBeLive::MESSAGE];
        }

        return [];
    }

    /**
     * Lock the automation, check its current actions, then write the live
     * flag. An edit of the automation locks the same row, so the check and
     * the write see the same actions.
     *
     * @throws ValidationException
     */
    public function setLive(Automation $automation, bool $isLive): void
    {
        DB::transaction(function () use ($automation, $isLive): void {
            $locked = Automation::query()->lockForUpdate()->findOrFail($automation->id);

            if ($isLive) {
                $problems = $this->activationProblems($locked);

                if ($problems !== []) {
                    throw ValidationException::withMessages([
                        'is_live' => implode(' ', $problems),
                    ]);
                }
            }

            $locked->update(['is_live' => $isLive]);
        });

        $automation->setAttribute('is_live', $isLive)->syncOriginalAttribute('is_live');
    }

    /**
     * @return list<int>
     */
    public function sequenceIds(Automation $automation): array
    {
        return collect($automation->actions ?? [])
            ->where('type', 'add_sequence')
            ->pluck('value')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
