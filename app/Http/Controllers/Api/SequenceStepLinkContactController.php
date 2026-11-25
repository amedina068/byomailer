<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LinkClickContactsRequest;
use App\Http\Resources\LinkClickContactResource;
use App\Models\SequenceStep;
use App\Services\LinkClickReport;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepLinkContactController extends Controller
{
    /**
     * List the contacts who clicked one link of a sequence step.
     *
     * Send the `key` of a link from the link list in the `link` parameter. Each
     * contact shows its number of clicks on that link and the time of the first
     * click. The list uses cursor pagination.
     */
    public function __invoke(LinkClickContactsRequest $request, int $sequenceId, int $sequenceStepId, LinkClickReport $linkClickReport): AnonymousResourceCollection
    {
        $step = SequenceStep::query()
            ->where('id', $sequenceStepId)
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->firstOrFail();

        return LinkClickContactResource::collection(
            $linkClickReport->clickers($step, $request->validated('link'))
                ->cursorPaginate($request->validated('per_page') ?? 50),
        );
    }
}
