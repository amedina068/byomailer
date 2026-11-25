<?php

namespace App\Http\Controllers;

use App\Http\Requests\SequenceStepFiltersRequest;
use App\Models\Sequence;
use App\Services\FilterPreparationService;
use Illuminate\Http\RedirectResponse;

class SequenceStepFiltersController extends Controller
{
    /**
     * Save the audience filters of one sequence step. A contact who does not
     * match them skips only this step's email.
     */
    public function __invoke(
        SequenceStepFiltersRequest $request,
        FilterPreparationService $filterPreparationService,
        $sequenceId,
        $sequenceStepId
    ): RedirectResponse {
        $sequence = Sequence::query()
            ->findOrFail($sequenceId);

        $step = $sequence->steps()->findOrFail($sequenceStepId);

        $step->forceFill([
            'filters' => $filterPreparationService->prepareFilters($request->validated('filters', []), $request->user()),
        ])->save();

        return back()->with('success', 'Step filters saved.');
    }
}
