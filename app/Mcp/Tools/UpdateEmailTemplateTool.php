<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateEmailTemplate;
use App\Concerns\EmailTemplateValidationRules;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FindsLiveTemplateUses;
use App\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the name or the HTML of an email template. Only the fields that you send change. The HTML must have exactly one {{ content }} placeholder and an unsubscribe link (href="{{ unsubscribe_url }}"), or confirmed_no_unsubscribe set to true after the user confirms. Messages that are not sent yet use the new HTML. If a scheduled or sending newsletter or a step of a live sequence uses the template, the template is live. Then the first call saves nothing and returns a preview with live_uses. Show the preview to the user. After the user confirms, call the tool again with the same arguments and confirm_live set to true.')]
class UpdateEmailTemplateTool extends Tool
{
    use ConfirmsLiveEdits;
    use EmailTemplateValidationRules;
    use FindsLiveTemplateUses;

    public function handle(Request $request, UpdateEmailTemplate $updateEmailTemplate): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_template_id' => ['required', 'integer'],
            ...$this->emailTemplateRules(partial: true),
            ...$this->confirmLiveValidationRules(),
        ]);

        $changes = Arr::only($validated, ['name', 'html_content']);

        if ($changes === []) {
            return Response::error('Send name, html_content or both. No change was saved.');
        }

        if (isset($changes['html_content'])) {
            $error = $this->missingUnsubscribeLinkError($changes['html_content'], (bool) ($validated['confirmed_no_unsubscribe'] ?? false));

            if ($error !== null) {
                throw ValidationException::withMessages(['html_content' => $error]);
            }
        }

        $template = EmailTemplate::query()->find($validated['email_template_id']);

        if (! $template) {
            return Response::error("No email template found with id {$validated['email_template_id']}.");
        }

        $liveUses = $this->liveTemplateUses($template);

        if ($liveUses !== [] && ! $this->confirmsLive($validated)) {
            return $this->statusChangePreview([
                'id' => $template->id,
                'name' => $template->name,
                'changes' => $this->describeChanges($template, $changes),
                'live_uses' => $liveUses,
                'impact' => 'The live records in live_uses send with this template. Their contacts receive the new version.',
            ]);
        }

        $updateEmailTemplate->handle($template, $changes);

        return Response::structured([
            'id' => $template->id,
            'name' => $template->name,
            'live_uses' => $liveUses,
            'applied' => true,
            'message' => "Email template #{$template->id} updated.",
        ]);
    }

    /**
     * @param  array{name?: string, html_content?: string}  $changes
     * @return array<string, mixed>
     */
    private function describeChanges(EmailTemplate $template, array $changes): array
    {
        $description = [];

        if (array_key_exists('name', $changes)) {
            $description['name'] = ['from' => $template->name, 'to' => $changes['name']];
        }

        if (array_key_exists('html_content', $changes)) {
            $description['html_content'] = $changes['html_content'] === $template->html_content ? 'unchanged' : 'replaced';
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_template_id' => $schema->integer()
                ->description('The id of the email template. Find it with list-email-templates-tool.')
                ->required(),
            'name' => $schema->string()
                ->description('The new name of the template.'),
            'html_content' => $schema->string()
                ->description('The new full HTML of the template, with exactly one {{ content }} placeholder. Maximum 500 KB.'),
            'confirmed_no_unsubscribe' => $schema->boolean()
                ->description('Set to true only after the user confirms to save HTML without an unsubscribe link.'),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
