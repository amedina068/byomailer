<?php

namespace App\Http\Controllers\Api;

use App\Actions\UpdateNewsletterContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateContentVariablesRequest;
use App\Models\Newsletter;
use App\Support\ContentVariables;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterVariablesController extends Controller
{
    /**
     * Get the content variables of a newsletter.
     *
     * Returns the `{{ variables }}` that the content of the newsletter uses, and
     * the saved default of each one. A default fills a variable when the contact
     * has no value for it.
     */
    public function show(int $newsletterId): JsonResponse
    {
        $newsletter = Newsletter::query()
            ->with(['message', 'messageB'])
            ->findOrFail($newsletterId);

        return $this->variablesResponse($newsletter);
    }

    /**
     * Replace the content variables of a newsletter.
     *
     * Replaces the content variable defaults of a newsletter with the status
     * `draft` or `scheduled`. The object replaces all saved defaults. A default
     * can name a variable that the content does not use yet. Each variable
     * that the content uses and the object leaves out gets an empty default.
     */
    public function update(UpdateContentVariablesRequest $request, UpdateNewsletterContent $updateNewsletterContent, int $newsletterId): JsonResponse
    {
        $newsletter = Newsletter::query()
            ->with(['message', 'messageB'])
            ->findOrFail($newsletterId);

        if (! $newsletter->isMutable()) {
            throw ValidationException::withMessages([
                'status' => ["Newsletters with status '{$newsletter->status->value}' cannot be modified. Only 'draft' and 'scheduled' newsletters are mutable."],
            ]);
        }

        $updateNewsletterContent->handle($request->user(), $newsletter, [
            'content_variables' => $request->validated('content_variables'),
        ]);

        return $this->variablesResponse($newsletter);
    }

    private function variablesResponse(Newsletter $newsletter): JsonResponse
    {
        $detected = $this->detectedVariables($newsletter);

        return response()->json([
            'data' => [
                'newsletter_id' => $newsletter->id,
                'detected_variables' => $detected,
                /** @var array<string, string> */
                'content_variables' => ContentVariables::withDetected($detected, $newsletter->content_variables),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function detectedVariables(Newsletter $newsletter): array
    {
        return $newsletter->detectedTemplateVariables();
    }
}
