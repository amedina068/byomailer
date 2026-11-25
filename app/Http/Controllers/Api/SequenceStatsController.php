<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SequenceStatsResource;
use App\Models\Sequence;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStatsController extends Controller
{
    /**
     * Get the statistics of a sequence.
     *
     * Returns the delivery and engagement counts of the sequence and of each step.
     */
    public function __invoke(Request $request, int $sequenceId): JsonResponse
    {
        $sequence = Sequence::query()
            ->with('steps')
            ->withCount('contacts')
            ->findOrFail($sequenceId);

        return SequenceStatsResource::make($sequence)->response();
    }
}
