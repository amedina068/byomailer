<?php

namespace App\Mcp\Concerns;

use App\Models\Automation;
use App\Models\User;
use App\Support\AutomationDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

trait HandlesAutomations
{
    /**
     * Validate a full automation payload with the same rules as the dashboard form.
     * Only a live automation needs live sequences in its "add_sequence" actions.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, type: string, condition: array<string, mixed>, actions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    protected function validateAutomation(User $user, array $input, bool $isLive): array
    {
        $input = AutomationDefinition::prepare($input);

        $validator = Validator::make($input, AutomationDefinition::rules($user, $input), AutomationDefinition::messages($input));

        $validator->after(function (ValidationValidator $validator) use ($user, $input, $isLive): void {
            AutomationDefinition::validateReferences($validator, $user, $input, $isLive);
        });

        $data = AutomationDefinition::normalize($validator->validate());

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'condition' => $data['condition'],
            'actions' => $data['actions'],
        ];
    }

    /**
     * @return array{id: int, name: string, type: string, is_live: bool, condition: array<string, mixed>, actions: list<array<string, mixed>>, created_at: ?string, updated_at: ?string}
     */
    protected function formatAutomation(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'type' => $automation->type,
            'is_live' => (bool) $automation->is_live,
            'condition' => $automation->condition ?? [],
            'actions' => array_values($automation->actions ?? []),
            'created_at' => $automation->created_at?->toIso8601String(),
            'updated_at' => $automation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function automationDefinitionSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name for the automation.'),
            'type' => $schema->string()
                ->enum([Automation::TYPE_SIMPLE])
                ->description('Automation type. The only type is "simple".'),
            'condition' => $schema->object([
                'type' => $schema->string()
                    ->enum(AutomationDefinition::CONDITION_TYPES)
                    ->description('The event that starts the automation.'),
                'value' => $schema->string()
                    ->description('The list, tag or sequence id of the event. For "attribute_changed" with operator "changed_to", the new attribute value. For "link_clicked", the link name.'),
                'attribute' => $schema->string()
                    ->description('Only for "attribute_changed": the contact attribute name.'),
                'operator' => $schema->string()
                    ->enum(['changed_to', 'changed'])
                    ->description('Only for "attribute_changed": "changed_to" needs a value, "changed" matches any change.'),
            ])->description('The trigger. Use view-automation-options to find the ids.'),
            'actions' => $schema->array()
                ->min(1)
                ->items($schema->object([
                    'type' => $schema->string()
                        ->enum(AutomationDefinition::ACTION_TYPES)
                        ->description('What to do with the contact.'),
                    'value' => $schema->integer()
                        ->description('The tag, list or sequence id. A paused automation can add contacts to a draft sequence. The sequence must be live before the automation goes live.'),
                ]))
                ->description('What happens to the contact, in order.'),
        ];
    }
}
