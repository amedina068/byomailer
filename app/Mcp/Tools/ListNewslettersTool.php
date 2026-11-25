<?php

namespace App\Mcp\Tools;

use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the newsletters belonging to the authenticated account, returning each id, title, status, subject and sent date. Use the returned id with the newsletter-stats-tool or newsletter-content-tool tools.')]
class ListNewslettersTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(NewsletterStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $newsletters = Newsletter::query()
            ->with('message:id,messageable_id,messageable_type,subject')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhereHas('message', fn ($mq) => $mq->where('subject', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (Newsletter $newsletter): array => [
                'id' => $newsletter->id,
                'title' => $newsletter->title,
                'status' => $newsletter->status->value,
                'subject' => $newsletter->message?->subject,
                'sent_at' => $newsletter->sent_at?->toIso8601String(),
            ])
            ->all();

        return Response::structured([
            'newsletters' => $newsletters,
            'count' => count($newsletters),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_column(NewsletterStatus::cases(), 'value'))
                ->description('Optional status filter.'),
            'search' => $schema->string()
                ->description('Optional case-insensitive search against the newsletter title and subject.'),
            'limit' => $schema->integer()
                ->description('Maximum number of newsletters to return (1-100, default 25).'),
        ];
    }
}
