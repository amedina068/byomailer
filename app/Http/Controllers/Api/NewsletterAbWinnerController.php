<?php

namespace App\Http\Controllers\Api;

use App\Actions\PickAbTestWinner;
use App\Enums\AbVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\NewsletterAbWinnerRequest;
use App\Http\Resources\Api\NewsletterResource;
use App\Models\Newsletter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterAbWinnerController extends Controller
{
    /**
     * Pick the A/B test winner.
     *
     * Stores variant `a` or `b` as the winner of an A/B test. The newsletter must
     * have the status `ab_testing`. Each half of the audience already got one
     * variant, so BYOMailer sends no more email. The newsletter gets the status
     * `sent`, and the `newsletter.sent` webhook event fires one time.
     */
    public function __invoke(NewsletterAbWinnerRequest $request, int $newsletterId, PickAbTestWinner $pickAbTestWinner): JsonResponse
    {
        $newsletter = $pickAbTestWinner->handle(
            Newsletter::query()->findOrFail($newsletterId),
            AbVariant::from($request->validated('winner_variant')),
        );

        $newsletter->load(['message.emailIdentity', 'messageB']);
        $newsletter->loadCount(Newsletter::metricsCountDefinitions());

        return NewsletterResource::make($newsletter)->response();
    }
}
