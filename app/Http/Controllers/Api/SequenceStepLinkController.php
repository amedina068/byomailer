<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SequenceStep;
use App\Services\LinkClickReport;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepLinkController extends Controller
{
    /**
     * List the links of a sequence step with their clicks.
     *
     * A named link (`data-bm-link` attribute) groups by its name. Any other link
     * groups by its URL without the `utm_*` parameters and the fragment. Use the
     * `key` of a link to list its contacts. The click rate is unique contacts
     * divided by delivered mails, times 100. Clicks can include link scanners,
     * for example Microsoft Safe Links.
     */
    public function __invoke(int $sequenceId, int $sequenceStepId, LinkClickReport $linkClickReport): JsonResponse
    {
        $step = SequenceStep::query()
            ->where('id', $sequenceStepId)
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->firstOrFail();

        return response()->json([
            'data' => $linkClickReport->summary($step),
        ]);
    }
}
