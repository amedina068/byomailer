<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SequenceStepStatsResource;
use App\Models\SequenceStep;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepStatsController extends Controller
{
    /**
     * Get the statistics of a sequence step.
     *
     * Returns the delivery and engagement counts of one sequence step.
     */
    public function __invoke(Request $request, int $sequenceId, int $sequenceStepId): JsonResponse
    {
        $step = SequenceStep::query()
            ->where('id', $sequenceStepId)
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->with('message')
            ->firstOrFail();

        return SequenceStepStatsResource::make($step)->response();
    }
}
