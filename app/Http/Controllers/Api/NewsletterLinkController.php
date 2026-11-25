<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Services\LinkClickReport;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterLinkController extends Controller
{
    /**
     * List the links of a newsletter with their clicks.
     *
     * A named link (`data-bm-link` attribute) groups by its name. Any other link
     * groups by its URL without the `utm_*` parameters and the fragment. Use the
     * `key` of a link to list its contacts. The click rate is unique contacts
     * divided by delivered mails, times 100. Clicks can include link scanners,
     * for example Microsoft Safe Links.
     */
    public function __invoke(int $newsletterId, LinkClickReport $linkClickReport): JsonResponse
    {
        $newsletter = Newsletter::query()->findOrFail($newsletterId);

        return response()->json([
            'data' => $linkClickReport->summary($newsletter),
        ]);
    }
}
