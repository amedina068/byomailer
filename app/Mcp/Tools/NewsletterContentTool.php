<?php

namespace App\Mcp\Tools;

use App\Http\Resources\NewsletterResource;
use App\Models\Newsletter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the full content of a single newsletter: title, subject, preview text, sender, html and text body, content variables (the saved default of each {{ variable }}), detected variables (the {{ variables }} that the content uses), and the B variant content when A/B testing is enabled. Find the newsletter id with the list-newsletters-tool tool.')]
class NewsletterContentTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'newsletter_id' => ['required', 'integer'],
        ]);

        $newsletter = Newsletter::query()
            ->with(['message.emailIdentity', 'message.template', 'messageB'])
            ->find($validated['newsletter_id']);

        if (! $newsletter) {
            return Response::error("No newsletter found with id {$validated['newsletter_id']}.");
        }

        $resource = NewsletterResource::make($newsletter)->resolve();

        return Response::structured([
            'id' => $resource['id'],
            'title' => $resource['title'],
            'status' => $resource['status'],
            'subject' => $resource['subject'],
            'preview_text' => $resource['preview_text'],
            'from_email' => $resource['from_email'],
            'from_name' => $resource['from_name'],
            'html_content' => $resource['html_content'],
            'text_content' => $resource['text_content'],
            'content_variables' => $resource['content_variables'],
            'detected_variables' => $newsletter->detectedTemplateVariables(),
            'ab_test_enabled' => $resource['ab_test_enabled'],
            'subject_b' => $resource['subject_b'],
            'preview_text_b' => $resource['preview_text_b'],
            'html_content_b' => $resource['html_content_b'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()
                ->description('The id of the newsletter to read content for.')
                ->required(),
        ];
    }
}
