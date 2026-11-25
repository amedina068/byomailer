<?php

namespace App\Http\Controllers;

use App\Enums\SequenceStatus;
use App\Http\Requests\UpdateSequenceStepStatusRequest;
use App\Models\Sequence;
use App\Services\SequenceStatusService;
use Illuminate\Http\RedirectResponse;

class SequenceStepStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateSequenceStepStatusRequest $request, SequenceStatusService $sequenceStatusService, $sequenceId, $sequenceStepId): RedirectResponse
    {
        $sequence = Sequence::query()
            ->findOrFail($sequenceId);

        $step = $sequence->steps()
            ->with('message')
            ->findOrFail($sequenceStepId);

        $status = $request->enum('status', SequenceStatus::class);

        $sequenceStatusService->setStepStatus($request->user(), $step, $status);

        $label = $status === SequenceStatus::Live ? 'activated' : 'paused';

        return back()->with('success', "Step {$label}.");
    }
}
