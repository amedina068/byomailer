<?php

namespace App\Http\Controllers;

use App\Actions\UpdateNewsletterContent;
use App\Http\Resources\NewsletterResource;
use App\Http\Resources\TopicResource;
use App\Models\ContactAttribute;
use App\Models\Newsletter;
use App\Models\Topic;
use App\Support\ContentVariables;
use App\Support\DetectsTemplateVariables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterVariablesController extends Controller
{
    use DetectsTemplateVariables;

    public function show(Request $request, $newsletterId): Response
    {
        $newsletter = Newsletter::query()
            ->mutable()
            ->with(['message', 'messageB', 'emailIdentity'])
            ->findOrFail($newsletterId);

        $variables = $this->extractVariablesFromNewsletter($newsletter);

        $globalDefaults = ContactAttribute::defaultsForNames($variables);
        $merged = ContactAttribute::mergePrefillDefaults(
            $variables,
            $newsletter->content_variables ?? [],
            $globalDefaults,
        );

        $client = $request->user()->client;

        return Inertia::render('newsletters/Create', [
            'draftNewsletter' => NewsletterResource::make($newsletter)->resolve($request),
            'initialStep' => 'variables',
            'detectedVariables' => $variables,
            'variableDefaults' => $merged,
            'globalVariableDefaults' => $globalDefaults,
            'lists' => $client->mailingLists()->orderBy('name')->get()->map->only(['id', 'name']),
            'emailIdentities' => $client->emailIdentities()->orderBy('email')->get()->map->only(['id', 'email', 'name']),
            'tags' => $client->tags()->orderBy('name')->get()->map->only(['id', 'name']),
            'topics' => TopicResource::collection(
                Topic::query()
                    ->with(['list:id,name'])
                    ->orderBy('name')
                    ->get(),
            )->resolve($request),
            'contactAttributes' => $client->contactAttributes()->orderBy('name')->pluck('name')->filter()->unique()->values()->all(),
        ]);
    }

    public function update(Request $request, UpdateNewsletterContent $updateNewsletterContent, $newsletterId): RedirectResponse
    {
        $newsletter = Newsletter::query()->mutable()->findOrFail($newsletterId);

        $validated = $request->validate(ContentVariables::rules());

        $updateNewsletterContent->handle($request->user(), $newsletter, [
            'content_variables' => $validated['content_variables'] ?? [],
        ]);

        return to_route('newsletters.filters.show', $newsletterId);
    }

    /**
     * @return array<int, string>
     */
    private function extractVariablesFromNewsletter(Newsletter $newsletter): array
    {
        $message = $newsletter->message;

        if (! $message) {
            return [];
        }

        $vars = collect($this->detectVariables($message->subject ?? ''))
            ->merge($this->detectVariables($message->body()));

        if ($newsletter->ab_test_enabled && $newsletter->messageB) {
            $messageB = $newsletter->messageB;
            $vars = $vars
                ->merge($this->detectVariables($messageB->subject ?? ''))
                ->merge($this->detectVariables($messageB->body()));
        }

        return $vars->unique()->sort()->values()->all();
    }
}
