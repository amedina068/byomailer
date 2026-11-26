<?php

namespace App\Mcp\Tools;

use App\Actions\CreateEmailTemplate;
use App\Concerns\EmailTemplateValidationRules;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create an email template for the authenticated account. The HTML must have exactly one {{ content }} placeholder, where the content of the newsletter or the sequence step goes. The HTML must have an unsubscribe link (href="{{ unsubscribe_url }}"). To save without the link, ask the user and send confirmed_no_unsubscribe as true. The first template of an account becomes the default. Returns the new template id.')]
class CreateEmailTemplateTool extends Tool
{
    use EmailTemplateValidationRules;

    public function handle(Request $request, CreateEmailTemplate $createEmailTemplate): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->emailTemplateRules());

        $error = $this->missingUnsubscribeLinkError($validated['html_content'], (bool) ($validated['confirmed_no_unsubscribe'] ?? false));

        if ($error !== null) {
            throw ValidationException::withMessages(['html_content' => $error]);
        }

        $template = $createEmailTemplate->handle($user, $validated['name'], $validated['html_content']);

        return Response::structured([
            'id' => $template->id,
            'name' => $template->name,
            'is_default' => (bool) $template->is_default,
            'message' => "Email template #{$template->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The name of the template.')
                ->required(),
            'html_content' => $schema->string()
                ->description('The full HTML of the template, with exactly one {{ content }} placeholder. Maximum 500 KB.')
                ->required(),
            'confirmed_no_unsubscribe' => $schema->boolean()
                ->description('Set to true only after the user confirms to save a template without an unsubscribe link.'),
        ];
    }
}
