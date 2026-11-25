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
#[Description('Get the delivery and engagement stats for a single newsletter: totals plus sent, delivered, opened, bounced, unsubscribed and complained counts with delivery/open/unsubscribe rates. Includes A/B variant metrics when A/B testing is enabled. Find the newsletter id with the list-newsletters-tool tool.')]
class NewsletterStatsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'newsletter_id' => ['required', 'integer'],
        ]);

        $newsletter = Newsletter::query()
            ->with(['message.emailIdentity', 'messageB'])
            ->withMetricsCounts((int) $validated['newsletter_id'])
            ->find($validated['newsletter_id']);

        if (! $newsletter) {
            return Response::error("No newsletter found with id {$validated['newsletter_id']}.");
        }

        $resource = NewsletterResource::make($newsletter)->resolve();

        return Response::structured([
            'id' => $resource['id'],
            'title' => $resource['title'],
            'status' => $resource['status'],
            'sent_at' => $newsletter->sent_at?->toIso8601String(),
            'metrics' => $resource['metrics'],
            'ab_metrics' => $resource['ab_metrics'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()
                ->description('The id of the newsletter to read stats for.')
                ->required(),
        ];
    }
}
