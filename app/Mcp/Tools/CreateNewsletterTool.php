<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateNewsletterContent;
use App\Enums\NewsletterStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\HandlesContentVariables;
use App\Models\Newsletter;
use App\Models\User;
use App\Rules\SafeHtml;
use App\Rules\SafeTemplatedHtml;
use App\Rules\ValidLiquid;
use App\Support\ContentVariables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a draft newsletter for the authenticated account. Drafts are never sent. Schedule a draft with schedule-newsletter after the user confirms. Optionally attach a sender (email_identity_id), template (email_template_id), audience filters and content_variables (the default value of each {{ variable }}). Use view-newsletter-filters-tool to build the filters array and calculate-newsletter-contacts-tool to preview the audience size first. Returns the new newsletter id and status.')]
class CreateNewsletterTool extends Tool
{
    use BuildsNewsletterFilters;
    use HandlesContentVariables;

    public function handle(Request $request, UpdateNewsletterContent $updateNewsletterContent): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
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
        ]);

        $newsletter = DB::transaction(function () use ($user, $validated, $updateNewsletterContent): Newsletter {
            $newsletter = Newsletter::create([
                'user_id' => $user->id,
                'title' => $validated['title'] ?? Newsletter::DEFAULT_TITLE,
                'status' => NewsletterStatus::Draft,
                'filters' => [],
            ]);

            return $updateNewsletterContent->handle($user, $newsletter, Arr::only($validated, UpdateNewsletterContent::FIELDS));
        });

        return Response::structured([
            'id' => $newsletter->id,
            'title' => $newsletter->title,
            'status' => $newsletter->status->value,
            'message' => "Draft newsletter #{$newsletter->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Internal title for the newsletter. Defaults to "Untitled newsletter".'),
            'subject' => $schema->string()->description('Email subject line. Supports {{ variable }} template tags.'),
            'preview_text' => $schema->string()->description('Inbox preview / preheader text.'),
            'html_content' => $schema->string()->description('HTML body of the email. Plain-text is derived automatically.'),
            'email_identity_id' => $schema->integer()->description('Verified sender identity id to send from. Required before the draft can be scheduled or test-mailed.'),
            'email_template_id' => $schema->integer()->description('Optional email template id to wrap the content.'),
            ...$this->filtersSchema($schema),
            'content_variables' => $this->contentVariablesSchema($schema),
        ];
    }
}
