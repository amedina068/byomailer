<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAutomations;
use App\Models\Automation;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show one automation of the authenticated account: name, type, live flag, trigger condition and actions. Each list, tag or sequence id in the condition and actions comes with its name, or null when the item no longer exists. Find automation ids with list-automations.')]
class AutomationDetailsTool extends Tool
{
    use HandlesAutomations;

    protected string $name = 'automation-details';

    /**
     * @var array<string, string>
     */
    private const REFERENCED_TABLES = [
        'contact_added_to_list' => 'lists',
        'removed_from_list' => 'lists',
        'tag_added' => 'tags',
        'removed_tag' => 'tags',
        'added_to_sequence' => 'sequences',
        'sequence_finished' => 'sequences',
        'add_tag' => 'tags',
        'remove_tag' => 'tags',
        'add_list' => 'lists',
        'remove_list' => 'lists',
        'add_sequence' => 'sequences',
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'automation_id' => ['required', 'integer'],
        ]);

        /** @var Automation|null $automation */
        $automation = Automation::query()->find($validated['automation_id']);

        if ($automation === null) {
            return Response::error("No automation found with id {$validated['automation_id']}.");
        }

        $details = $this->formatAutomation($automation);

        $ids = collect([$details['condition'], ...$details['actions']])
            ->filter(fn (array $item): bool => isset(self::REFERENCED_TABLES[$item['type'] ?? '']))
            ->groupBy(fn (array $item): string => self::REFERENCED_TABLES[$item['type']])
            ->map(fn ($items): array => $items->pluck('value')->map(fn ($value): int => (int) $value)->unique()->values()->all());

        $names = [
            'lists' => MailList::query()->whereIn('id', $ids->get('lists', []))->pluck('name', 'id')->all(),
            'tags' => Tag::query()->whereIn('id', $ids->get('tags', []))->pluck('name', 'id')->all(),
            'sequences' => Sequence::query()->whereIn('id', $ids->get('sequences', []))->pluck('name', 'id')->all(),
        ];

        $details['condition'] = $this->withName($details['condition'], $names);
        $details['actions'] = array_map(fn (array $action): array => $this->withName($action, $names), $details['actions']);

        return Response::structured($details);
    }

    /**
     * Add the name of the list, tag or sequence that the item references.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<int, string>>  $names
     * @return array<string, mixed>
     */
    private function withName(array $item, array $names): array
    {
        $table = self::REFERENCED_TABLES[$item['type'] ?? ''] ?? null;

        if ($table === null) {
            return $item;
        }

        return [...$item, 'name' => $names[$table][(int) ($item['value'] ?? 0)] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_id' => $schema->integer()
                ->description('Id of the automation. Use list-automations to discover ids.')
                ->required(),
        ];
    }
}
