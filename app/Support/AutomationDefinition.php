<?php

namespace App\Support;

use App\Models\Automation;
use App\Models\User;
use App\Rules\SequenceMustBeLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation and normalization of an automation's name, type, condition and actions.
 * The dashboard form request and the MCP tools share it, so both accept the same payload.
 */
class AutomationDefinition
{
    public const ATTRIBUTE_CHANGED = 'attribute_changed';

    public const LINK_CLICKED = 'link_clicked';

    public const CONDITION_TYPES = [
        'contact_added_to_list',
        'removed_from_list',
        'tag_added',
        'removed_tag',
        'added_to_sequence',
        'sequence_finished',
        self::ATTRIBUTE_CHANGED,
        self::LINK_CLICKED,
    ];

    public const ACTION_TYPES = ['add_tag', 'add_list', 'remove_tag', 'remove_list', 'add_sequence'];

    /**
     * Trim a string condition value before validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        $condition = $input['condition'] ?? null;

        if (is_array($condition) && is_string($condition['value'] ?? null)) {
            $input['condition'] = [...$condition, 'value' => trim($condition['value'])];
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<mixed>>
     */
    public static function rules(User $user, array $input): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([Automation::TYPE_SIMPLE])],
            'condition' => ['required', 'array'],
            'condition.type' => ['required', Rule::in(self::CONDITION_TYPES)],
            'condition.value' => ['required', 'integer'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(self::ACTION_TYPES)],
            'actions.*.value' => ['nullable', 'integer'],
            ...self::attributeConditionRules($user, $input),
            ...self::linkConditionRules($input),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function messages(array $input): array
    {
        $messages = [
            'condition.attribute.exists' => 'Selected attribute is invalid.',
            'condition.operator.in' => 'Select "changes to" or "changes".',
            'condition.value.max' => 'The value must be 255 characters or fewer.',
        ];

        if (data_get($input, 'condition.type') === self::ATTRIBUTE_CHANGED) {
            $messages['condition.value.required'] = 'Enter the value that the attribute changes to.';
        }

        if (data_get($input, 'condition.type') === self::LINK_CLICKED) {
            $messages['condition.value.required'] = 'Enter the name of the link.';
            $messages['condition.value.max'] = 'The link name must be '.NamedLink::MAX_LENGTH.' characters or fewer.';
        }

        return $messages;
    }

    /**
     * Check that the lists, tags and sequences in the condition and actions belong to the user.
     *
     * A live automation drops each contact that it cannot enroll in a sequence
     * that is not live. So a live automation must point to live sequences. A
     * paused automation runs on no contact, so it can point to a draft sequence.
     * The sequence must then go live before the automation goes live.
     *
     * @param  array<string, mixed>  $input
     */
    public static function validateReferences(Validator $validator, User $user, array $input, bool $requireLiveSequences = true): void
    {
        $conditionType = data_get($input, 'condition.type');
        $conditionValue = data_get($input, 'condition.value');

        if (in_array($conditionType, ['contact_added_to_list', 'removed_from_list'], true) && ! self::valueExists('lists', $conditionValue, $user->client_id)) {
            $validator->errors()->add('condition.value', 'Selected list is invalid.');
        }

        if (in_array($conditionType, ['tag_added', 'removed_tag'], true) && ! self::valueExists('tags', $conditionValue, $user->client_id)) {
            $validator->errors()->add('condition.value', 'Selected tag is invalid.');
        }

        if (in_array($conditionType, ['added_to_sequence', 'sequence_finished'], true) && ! self::valueExists('sequences', $conditionValue, $user->client_id)) {
            $validator->errors()->add('condition.value', 'Selected sequence is invalid.');
        }

        if ($conditionType === self::LINK_CLICKED && is_string($conditionValue) && NamedLink::normalize($conditionValue) === null) {
            $validator->errors()->add('condition.value', 'The link name must contain a letter or a digit.');
        }

        $actions = $input['actions'] ?? [];

        foreach (is_array($actions) ? $actions : [] as $index => $action) {
            $value = $action['value'] ?? null;
            $type = $action['type'] ?? null;

            if (! $value) {
                $validator->errors()->add("actions.$index.value", 'Action value is required.');

                continue;
            }

            if (in_array($type, ['add_tag', 'remove_tag'], true) && ! self::valueExists('tags', $value, $user->client_id)) {
                $validator->errors()->add("actions.$index.value", 'Tag action value is invalid.');
            }

            if (in_array($type, ['add_list', 'remove_list'], true) && ! self::valueExists('lists', $value, $user->client_id)) {
                $validator->errors()->add("actions.$index.value", 'List action value is invalid.');
            }

            if ($type === 'add_sequence') {
                if (! self::valueExists('sequences', $value, $user->client_id)) {
                    $validator->errors()->add("actions.$index.value", 'Sequence action value is invalid.');
                } elseif ($requireLiveSequences && ! SequenceMustBeLive::allLive([$value])) {
                    $validator->errors()->add("actions.$index.value", SequenceMustBeLive::MESSAGE);
                }
            }
        }
    }

    /**
     * Keep only the condition keys of its type, and store each list, tag and
     * sequence id as an integer. The dashboard form sends the ids as strings.
     * The automation listeners compare the stored JSON value with integer ids.
     * A link name is stored in the form that the click events carry, so
     * "Black Friday" matches the link named "black-friday".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $data['condition'] = match ($data['condition']['type']) {
            self::ATTRIBUTE_CHANGED => [
                'type' => self::ATTRIBUTE_CHANGED,
                'attribute' => $data['condition']['attribute'],
                'operator' => $data['condition']['operator'],
                'value' => $data['condition']['operator'] === 'changed_to' ? $data['condition']['value'] : null,
            ],
            self::LINK_CLICKED => [
                'type' => self::LINK_CLICKED,
                'value' => NamedLink::normalize($data['condition']['value']),
            ],
            default => [
                'type' => $data['condition']['type'],
                'value' => (int) $data['condition']['value'],
            ],
        };

        $data['actions'] = array_map(fn (array $action): array => [
            'type' => $action['type'],
            'value' => (int) $action['value'],
        ], array_values($data['actions']));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<mixed>>
     */
    private static function attributeConditionRules(User $user, array $input): array
    {
        if (data_get($input, 'condition.type') !== self::ATTRIBUTE_CHANGED) {
            return [];
        }

        $valueRules = data_get($input, 'condition.operator') === 'changed_to'
            ? ['required', 'string', 'max:255']
            : ['nullable'];

        return [
            'condition.attribute' => [
                'required',
                'string',
                'max:255',
                Rule::exists('contact_attributes', 'name')->where('client_id', $user->client_id),
            ],
            'condition.operator' => ['required', 'in:changed_to,changed'],
            'condition.value' => $valueRules,
        ];
    }

    /**
     * A link name is free text, not an id.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<mixed>>
     */
    private static function linkConditionRules(array $input): array
    {
        if (data_get($input, 'condition.type') !== self::LINK_CLICKED) {
            return [];
        }

        return [
            'condition.value' => ['required', 'string', 'max:'.NamedLink::MAX_LENGTH],
        ];
    }

    private static function valueExists(string $table, mixed $value, int $clientId): bool
    {
        return $value && DB::table($table)
            ->where('id', $value)
            ->where('client_id', $clientId)
            ->exists();
    }
}
