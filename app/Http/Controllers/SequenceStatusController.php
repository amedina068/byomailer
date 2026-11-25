<?php

namespace App\Http\Controllers;

use App\Enums\SequenceStatus;
use App\Http\Requests\UpdateSequenceStatusRequest;
use App\Models\Sequence;
use App\Services\SequenceStatusService;
use Illuminate\Http\RedirectResponse;

class SequenceStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateSequenceStatusRequest $request, SequenceStatusService $sequenceStatusService, $sequenceId): RedirectResponse
    {
        $sequence = Sequence::query()
            ->findOrFail($sequenceId);

        $status = $request->enum('status', SequenceStatus::class);

        $sequenceStatusService->setSequenceStatus($request->user(), $sequence, $status);

        $label = $status === SequenceStatus::Live ? 'activated' : 'paused';

        return back()->with('success', "Sequence {$label}.");
    }
}
