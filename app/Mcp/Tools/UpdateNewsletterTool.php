<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateNewsletterContent;
use App\Enums\NewsletterStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\HandlesContentVariables;
use App\Models\Newsletter;
use App\Models\User;
use App\Rules\SafeHtml;
use App\Rules\SafeTemplatedHtml;
use App\Rules\ValidLiquid;
use App\Support\ContentVariables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a draft or scheduled newsletter for the authenticated account. A scheduled newsletter is live: it sends to real contacts at its scheduled time, so the tool refuses the change until you ask the user to confirm and call it again with confirm_live set to true. A newsletter that is sending or already sent is locked and this tool returns an error. The status never changes here: use schedule-newsletter to schedule or unschedule. Pass content_variables to replace the default value of each {{ variable }}. Only the fields you pass are changed; omitted fields keep their current values. Use view-newsletter-filters-tool to build the filters array and calculate-newsletter-contacts-tool to preview the audience size first. Returns the newsletter id and status.')]
class UpdateNewsletterTool extends Tool
{
    use BuildsNewsletterFilters;
    use ConfirmsLiveEdits;
    use HandlesContentVariables;

    public function handle(Request $request, UpdateNewsletterContent $updateNewsletterContent): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'newsletter_id' => ['required', 'integer'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255', new ValidLiquid],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255', new SafeHtml, new ValidLiquid],
            'html_content' => ['sometimes', 'nullable', 'string', 'max:524288', new SafeTemplatedHtml, new ValidLiquid],
            'email_identity_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('email_identities', 'id')->where('client_id', $user->client_id),
            ],
            'email_template_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('email_templates', 'id')->where('client_id', $user->client_id),
            ],
            ...ContentVariables::rules(),
            ...$this->filterValidationRules(),
            ...$this->confirmLiveValidationRules(),
        ]);

        $newsletter = Newsletter::query()->find($validated['newsletter_id']);

        if ($newsletter === null) {
            return Response::error('Newsletter not found.');
        }

        if (! $newsletter->isMutable()) {
            return Response::error("Newsletter #{$newsletter->id} is {$newsletter->status->value} and can no longer be edited. Only draft and scheduled newsletters can be updated.");
        }

        if ($newsletter->status === NewsletterStatus::Scheduled) {
            $scheduledAt = $newsletter->scheduled_at?->toIso8601String() ?? 'its scheduled time';
            $refusal = $this->liveEditNeedsConfirmation($validated, "Newsletter #{$newsletter->id}", "It is scheduled to send to real contacts at {$scheduledAt}.");

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $updateNewsletterContent->handle(
            $user,
            $newsletter,
            Arr::only($validated, UpdateNewsletterContent::FIELDS),
            scheduledAllowed: $this->confirmsLive($validated),
        );

        $newsletter->refresh();

        return Response::structured([
            'id' => $newsletter->id,
            'title' => $newsletter->title,
            'status' => $newsletter->status->value,
            'message' => $newsletter->status->label()." newsletter #{$newsletter->id} updated.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()->description('Id of the draft or scheduled newsletter to update. Use list-newsletters-tool to discover ids.')->required(),
            'title' => $schema->string()->description('Internal title for the newsletter.'),
            'subject' => $schema->string()->description('Email subject line. Supports Liquid: {{ variable }} placeholders, filters such as {{ name | default: "friend" }}, and tags such as {% if %}.'),
            'preview_text' => $schema->string()->description('Inbox preview / preheader text.'),
            'html_content' => $schema->string()->description('HTML body of the email. Plain-text is derived automatically. Supports Liquid: {{ variable }} placeholders, filters, and the {% if %}, {% unless %}, {% case %} and {% for %} tags. Each output is HTML-escaped. Content that is not valid Liquid is refused with the line of the error.'),
            'email_identity_id' => $schema->integer()->description('Verified sender identity id to send from. Required before the draft can be scheduled or test-mailed.'),
            'email_template_id' => $schema->integer()->description('Optional email template id to wrap the content.'),
            ...$this->filtersSchema($schema),
            'content_variables' => $this->contentVariablesSchema($schema),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
