<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SequenceStepFiltersRequest;
use App\Http\Resources\SequenceStepResource;
use App\Models\SequenceStep;
use App\Services\FilterPreparationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepFiltersController extends Controller
{
    /**
     * Replace the filters of a sequence step.
     *
     * Replaces the audience filters of one sequence step. A contact who does not
     * match them skips only the email of this step. An empty array clears the
     * filters.
     */
    public function __invoke(
        SequenceStepFiltersRequest $request,
        FilterPreparationService $filterPreparationService,
        int $sequenceId,
        int $sequenceStepId
    ): JsonResponse {
        $step = SequenceStep::query()
            ->where('id', $sequenceStepId)
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->firstOrFail();

        $step->forceFill([
            'filters' => $filterPreparationService->prepareFilters($request->validated('filters', []), $request->user()),
        ])->save();

        $step->load(['message.emailIdentity', 'message.template']);

        return SequenceStepResource::make($step)->response();
    }
}
