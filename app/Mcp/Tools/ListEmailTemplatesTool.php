<?php

namespace App\Mcp\Tools;

use App\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the email templates of the authenticated account, newest first. A template is the HTML layout around the content of a newsletter or a sequence step. Archived templates are left out unless archived is true. Returns each id, name, default flag and dates, without the HTML. Read the HTML with email-template-content-tool. Use the id as email_template_id on the newsletter and sequence step tools.')]
class ListEmailTemplatesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'archived' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $templates = EmailTemplate::query()
            ->when((bool) ($validated['archived'] ?? false), fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->when(isset($validated['is_default']), fn ($query) => $query->where('is_default', (bool) $validated['is_default']))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (EmailTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'is_default' => (bool) $template->is_default,
                'archived_at' => $template->archived_at?->toIso8601String(),
                'created_at' => $template->created_at?->toIso8601String(),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ])
            ->all();

        return Response::structured([
            'templates' => $templates,
            'count' => count($templates),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'archived' => $schema->boolean()
                ->description('Set to true to list only the archived templates. Default false.'),
            'is_default' => $schema->boolean()
                ->description('Optional filter: true for the default template only, false for the other templates.'),
            'search' => $schema->string()
                ->description('Optional search against the template name.'),
            'limit' => $schema->integer()
                ->description('Maximum number of templates to return (1-100, default 25).'),
        ];
    }
}
