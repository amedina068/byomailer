<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LinkClickContactsRequest;
use App\Http\Resources\LinkClickContactResource;
use App\Models\Newsletter;
use App\Services\LinkClickReport;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterLinkContactController extends Controller
{
    /**
     * List the contacts who clicked one link of a newsletter.
     *
     * Send the `key` of a link from the link list in the `link` parameter. Each
     * contact shows its number of clicks on that link and the time of the first
     * click. The list uses cursor pagination.
     */
    public function __invoke(LinkClickContactsRequest $request, int $newsletterId, LinkClickReport $linkClickReport): AnonymousResourceCollection
    {
        $newsletter = Newsletter::query()->findOrFail($newsletterId);

        return LinkClickContactResource::collection(
            $linkClickReport->clickers($newsletter, $request->validated('link'))
                ->cursorPaginate($request->validated('per_page') ?? 50),
        );
    }
}
