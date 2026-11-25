<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NewsletterContactsPreviewRequest;
use App\Services\ContactFilterService;
use App\Services\FilterPreparationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterContactsPreviewController extends Controller
{
    public function __construct(
        private readonly FilterPreparationService $filterPreparationService,
        private readonly ContactFilterService $contactFilterService,
    ) {}

    /**
     * Count the recipients of filters.
     *
     * Returns the number of contacts that match the given audience filters.
     * Nothing is saved. A read-only token can call this endpoint. The limit is 30
     * requests a minute for each user.
     */
    public function __invoke(NewsletterContactsPreviewRequest $request): JsonResponse
    {
        $filters = $this->filterPreparationService->prepareFilters(
            $request->validated('filters', []),
            $request->user(),
        );

        $count = $this->contactFilterService->newsletterRecipientsQuery(
            $request->user()->client_id,
            $filters,
        )->count();

        return response()->json(['count' => $count]);
    }
}
