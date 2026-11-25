<?php

namespace App\Mcp\Tools;

use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Support\AutomationDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Describe the building blocks of an automation for the authenticated account: the trigger condition types, the action types, and the lists, tags, sequences and contact attributes that a condition or action can reference by id or name. Use the result to assemble the "condition" and "actions" for create-automation and update-automation. A paused automation can use a draft sequence in an "add_sequence" action. The automation can go live only when that sequence is live.')]
class ViewAutomationOptionsTool extends Tool
{
    protected string $name = 'view-automation-options';

    /**
     * @var array<string, string>
     */
    private const CONDITION_DESCRIPTIONS = [
        'contact_added_to_list' => 'A contact is added to the list. value = list id.',
        'removed_from_list' => 'A contact is removed from the list. value = list id.',
        'tag_added' => 'A tag is added to a contact. value = tag id.',
        'removed_tag' => 'A tag is removed from a contact. value = tag id.',
        'added_to_sequence' => 'A contact is added to the sequence. value = sequence id.',
        'sequence_finished' => 'A contact finishes the sequence. value = sequence id.',
        'attribute_changed' => 'A contact attribute changes. attribute = attribute name, operator = "changed_to" (value = the new value) or "changed" (no value).',
    ];

    /**
     * @var array<string, string>
     */
    private const ACTION_DESCRIPTIONS = [
        'add_tag' => 'Add the tag to the contact. value = tag id.',
        'remove_tag' => 'Remove the tag from the contact. value = tag id.',
        'add_list' => 'Add the contact to the list. value = list id.',
        'remove_list' => 'Remove the contact from the list. value = list id.',
        'add_sequence' => 'Add the contact to the sequence. value = sequence id. The sequence must be live before the automation goes live.',
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        return Response::structured([
            'condition_types' => collect(AutomationDefinition::CONDITION_TYPES)
                ->map(fn (string $type): array => ['type' => $type, 'description' => self::CONDITION_DESCRIPTIONS[$type]])
                ->all(),
            'action_types' => collect(AutomationDefinition::ACTION_TYPES)
                ->map(fn (string $type): array => ['type' => $type, 'description' => self::ACTION_DESCRIPTIONS[$type]])
                ->all(),
            'available' => [
                'lists' => MailList::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($list): array => ['id' => $list->id, 'name' => $list->name])->all(),
                'tags' => Tag::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name])->all(),
                'sequences' => Sequence::query()->orderBy('name')->get(['id', 'name', 'status'])
                    ->map(fn ($sequence): array => ['id' => $sequence->id, 'name' => $sequence->name, 'status' => $sequence->status->value])->all(),
                'attributes' => ContactAttribute::query()
                    ->where('client_id', $user->client_id)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
