<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAutomations;
use App\Models\Automation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the automations belonging to the authenticated account, returning each id, name, type, live flag, trigger condition and actions. Condition and action values are raw ids — use automation-details to see them with names. Use the returned id with automation-details or update-automation.')]
class ListAutomationsTool extends Tool
{
    use HandlesAutomations;

    protected string $name = 'list-automations';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'is_live' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $automations = Automation::query()
            ->when(isset($validated['is_live']), fn ($query) => $query->where('is_live', (bool) $validated['is_live']))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (Automation $automation): array => $this->formatAutomation($automation))
            ->all();

        return Response::structured([
            'automations' => $automations,
            'count' => count($automations),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'is_live' => $schema->boolean()
                ->description('Optional filter: true for live automations, false for paused ones.'),
            'search' => $schema->string()
                ->description('Optional case-insensitive search against the automation name.'),
            'limit' => $schema->integer()
                ->description('Maximum number of automations to return (1-100, default 25).'),
        ];
    }
}
