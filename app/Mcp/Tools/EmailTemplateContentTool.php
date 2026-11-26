<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FindsLiveTemplateUses;
use App\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show one email template with its HTML. The HTML has one {{ content }} placeholder, where the content of the newsletter or the sequence step goes. live_uses lists the scheduled or sending newsletters and the steps of live sequences that use the template. A change to the template reaches the contacts of these records. Find the id with list-email-templates-tool.')]
class EmailTemplateContentTool extends Tool
{
    use FindsLiveTemplateUses;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_template_id' => ['required', 'integer'],
        ]);

        $template = EmailTemplate::query()->find($validated['email_template_id']);

        if (! $template) {
            return Response::error("No email template found with id {$validated['email_template_id']}.");
        }

        return Response::structured([
            'id' => $template->id,
            'name' => $template->name,
            'is_default' => (bool) $template->is_default,
            'archived_at' => $template->archived_at?->toIso8601String(),
            'html_content' => $template->html_content,
            'live_uses' => $this->liveTemplateUses($template),
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_template_id' => $schema->integer()
                ->description('The id of the email template.')
                ->required(),
        ];
    }
}
