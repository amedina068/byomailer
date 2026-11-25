<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\VisualAutomation;
use App\Rules\SequenceMustBeLive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisualAutomationRequest extends FormRequest
{
    use ValidatesProjectIds;

    /**
     * The model of each category of resource that a workflow can use.
     *
     * @var array<string, class-string<Tag|MailList|Topic|Sequence>>
     */
    private const RESOURCE_MODELS = [
        'tags' => Tag::class,
        'lists' => MailList::class,
        'topics' => Topic::class,
        'sequences' => Sequence::class,
    ];

    /**
     * The resource category of each trigger, action and condition type.
     *
     * @var array<string, string>
     */
    private const TYPE_CATEGORIES = [
        'add_tag' => 'tags', 'remove_tag' => 'tags', 'has_tag' => 'tags', 'not_has_tag' => 'tags', 'tag_added' => 'tags', 'removed_tag' => 'tags',
        'add_list' => 'lists', 'remove_list' => 'lists', 'in_list' => 'lists', 'not_in_list' => 'lists', 'contact_added_to_list' => 'lists', 'removed_from_list' => 'lists',
        'subscribed_to_topic' => 'topics', 'not_subscribed_to_topic' => 'topics',
        'add_sequence' => 'sequences', 'in_sequence' => 'sequences', 'not_in_sequence' => 'sequences', 'added_to_sequence' => 'sequences', 'sequence_finished' => 'sequences',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'workflow' => ['required', 'array'],
            'workflow.nodes' => ['required', 'array', 'min:1'],
            'workflow.nodes.*.id' => ['required', 'string'],
            'workflow.nodes.*.type' => ['required', 'in:trigger,condition,action,delay'],
            'workflow.nodes.*.position' => ['required', 'array'],
            'workflow.nodes.*.position.x' => ['required', 'numeric'],
            'workflow.nodes.*.position.y' => ['required', 'numeric'],
            'workflow.nodes.*.data' => ['nullable', 'array'],
            'workflow.nodes.*.data.trigger_type' => ['nullable', 'string', Rule::in([
                'contact_added_to_list', 'removed_from_list',
                'tag_added', 'removed_tag',
                'added_to_sequence', 'sequence_finished',
            ])],
            'workflow.nodes.*.data.action_type' => ['nullable', 'string', Rule::in([
                'add_tag', 'remove_tag',
                'add_list', 'remove_list',
                'add_sequence', 'unsubscribe_contact',
            ])],
            'workflow.nodes.*.data.condition_type' => ['nullable', 'string', Rule::in([
                'has_tag', 'not_has_tag',
                'in_list', 'not_in_list',
                'subscribed_to_topic', 'not_subscribed_to_topic',
                'in_sequence', 'not_in_sequence',
            ])],
            'workflow.nodes.*.data.value' => ['nullable', 'integer'],
            'workflow.nodes.*.data.duration' => ['nullable', 'integer', 'min:1'],
            'workflow.nodes.*.data.unit' => ['nullable', 'string', Rule::in(['minutes', 'hours', 'days'])],
            'workflow.edges' => ['present', 'array'],
            'workflow.edges.*.id' => ['required', 'string'],
            'workflow.edges.*.source' => ['required', 'string'],
            'workflow.edges.*.target' => ['required', 'string'],
            'workflow.edges.*.sourceHandle' => ['nullable', 'string'],
            'workflow.edges.*.targetHandle' => ['nullable', 'string'],
            ...$this->ruleRules('goals'),
            ...$this->ruleRules('exit_conditions'),
            ...$this->projectIdRules(),
        ];
    }

    /**
     * The rules for the goals or the exit conditions of the workflow.
     *
     * @return array<string, array<mixed>>
     */
    private function ruleRules(string $key): array
    {
        return [
            "workflow.{$key}" => ['nullable', 'array', 'max:10'],
            "workflow.{$key}.*" => ['array'],
            "workflow.{$key}.*.type" => ['required', 'string', Rule::in(VisualAutomation::RULE_TYPES)],
            "workflow.{$key}.*.value" => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'workflow.goals' => 'goals',
            'workflow.goals.*.type' => 'goal type',
            'workflow.goals.*.value' => 'goal value',
            'workflow.exit_conditions' => 'exit conditions',
            'workflow.exit_conditions.*.type' => 'exit condition type',
            'workflow.exit_conditions.*.value' => 'exit condition value',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $nodes = $this->input('workflow.nodes', []);
            $edges = $this->input('workflow.edges', []);
            $triggerCount = collect($nodes)->where('type', 'trigger')->count();

            if ($triggerCount === 0) {
                $validator->errors()->add('workflow.nodes', 'A workflow must have a trigger node.');
            }

            if ($triggerCount > 1) {
                $validator->errors()->add('workflow.nodes', 'A workflow can only have one trigger node.');
            }

            $nodeIds = collect($nodes)->pluck('id')->filter()->all();

            foreach ($edges as $index => $edge) {
                if (! in_array($edge['source'] ?? null, $nodeIds, true)) {
                    $validator->errors()->add("workflow.edges.{$index}.source", 'Edge references a non-existent source node.');
                }
                if (! in_array($edge['target'] ?? null, $nodeIds, true)) {
                    $validator->errors()->add("workflow.edges.{$index}.target", 'Edge references a non-existent target node.');
                }
            }

            $this->validateResourceOwnership($validator, $nodes);
        });
    }

    /**
     * Validate that the tags, lists, topics and sequences of the nodes, the
     * goals and the exit conditions belong to the client of the user.
     */
    private function validateResourceOwnership(Validator $validator, array $nodes): void
    {
        $user = $this->user();
        if (! $user) {
            return;
        }

        $this->validateOwnedIds($validator, 'workflow.nodes', $user->client_id, array_map(
            fn (mixed $node): array => (array) (is_array($node) ? ($node['data'] ?? []) : []),
            $nodes,
        ));

        foreach (['workflow.goals', 'workflow.exit_conditions'] as $key) {
            $this->validateOwnedIds($validator, $key, $user->client_id, (array) $this->input($key, []));
        }

        $addSequenceIds = [];
        foreach ($nodes as $node) {
            $data = $node['data'] ?? [];
            if (($data['action_type'] ?? null) === 'add_sequence' && isset($data['value'])) {
                $addSequenceIds[] = (int) $data['value'];
            }
        }

        if ($addSequenceIds && ! SequenceMustBeLive::allLive($addSequenceIds)) {
            $validator->errors()->add('workflow.nodes', SequenceMustBeLive::MESSAGE);
        }
    }

    /**
     * Add an error to the key for each category with an id of another client.
     * Each step is the data of a node, a goal, or an exit condition.
     *
     * @param  array<int, mixed>  $steps
     */
    private function validateOwnedIds(Validator $validator, string $key, int $clientId, array $steps): void
    {
        $idsByCategory = [];

        foreach ($steps as $step) {
            if (! is_array($step) || ! is_numeric($step['value'] ?? null)) {
                continue;
            }

            $type = $step['action_type'] ?? $step['condition_type'] ?? $step['trigger_type'] ?? $step['type'] ?? '';

            if (is_string($type) && isset(self::TYPE_CATEGORIES[$type])) {
                $idsByCategory[self::TYPE_CATEGORIES[$type]][] = (int) $step['value'];
            }
        }

        foreach ($idsByCategory as $category => $ids) {
            $ids = array_values(array_unique($ids));
            $ownedCount = self::RESOURCE_MODELS[$category]::query()->where('client_id', $clientId)->whereKey($ids)->count();

            if ($ownedCount !== count($ids)) {
                $validator->errors()->add($key, "One or more selected {$category} do not belong to your account.");
            }
        }
    }
}
