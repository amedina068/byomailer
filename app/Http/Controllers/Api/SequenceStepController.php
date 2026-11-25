<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SequenceStepResource;
use App\Models\SequenceStep;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepController extends Controller
{
    /**
     * Get a sequence step.
     *
     * Returns one step of the sequence, with its message, its email identity and
     * its template.
     */
    public function show(Request $request, int $sequenceId, int $sequenceStepId): JsonResponse
    {
        $step = SequenceStep::query()
            ->where('id', $sequenceStepId)
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->with(['message.emailIdentity', 'message.template'])
            ->firstOrFail();

        return SequenceStepResource::make($step)->response();
    }
}
