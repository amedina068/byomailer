<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Rules\SequenceMustBeLive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisualAutomationRequest extends FormRequest
{
    use ValidatesProjectIds;

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
            ...$this->projectIdRules(),
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
     * Validate that resource IDs (tags, lists, sequences) referenced in nodes belong to the authenticated user.
     */
    private function validateResourceOwnership(Validator $validator, array $nodes): void
    {
        $user = $this->user();
        if (! $user) {
            return;
        }

        $tagIds = [];
        $listIds = [];
        $topicIds = [];
        $sequenceIds = [];

        foreach ($nodes as $node) {
            $data = $node['data'] ?? [];
            $value = $data['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $value = (int) $value;
            $type = $data['action_type'] ?? $data['condition_type'] ?? $data['trigger_type'] ?? '';

            match ($type) {
                'add_tag', 'remove_tag', 'has_tag', 'not_has_tag', 'tag_added', 'removed_tag' => $tagIds[] = $value,
                'add_list', 'remove_list', 'in_list', 'not_in_list', 'contact_added_to_list', 'removed_from_list' => $listIds[] = $value,
                'subscribed_to_topic', 'not_subscribed_to_topic' => $topicIds[] = $value,
                'add_sequence', 'in_sequence', 'not_in_sequence', 'added_to_sequence', 'sequence_finished' => $sequenceIds[] = $value,
                default => null,
            };
        }

        if ($tagIds && Tag::query()->where('client_id', $user->client_id)->whereIn('tags.id', array_unique($tagIds))->count() !== count(array_unique($tagIds))) {
            $validator->errors()->add('workflow.nodes', 'One or more selected tags do not belong to your account.');
        }

        if ($listIds && MailList::query()->where('client_id', $user->client_id)->whereIn('lists.id', array_unique($listIds))->count() !== count(array_unique($listIds))) {
            $validator->errors()->add('workflow.nodes', 'One or more selected lists do not belong to your account.');
        }

        if ($topicIds) {
            $ownedTopicCount = Topic::query()
                ->where('client_id', $user->client_id)
                ->whereIn('id', array_unique($topicIds))
                ->count();
            if ($ownedTopicCount !== count(array_unique($topicIds))) {
                $validator->errors()->add('workflow.nodes', 'One or more selected topics do not belong to your account.');
            }
        }

        if ($sequenceIds && Sequence::query()->where('client_id', $user->client_id)->whereIn('sequences.id', array_unique($sequenceIds))->count() !== count(array_unique($sequenceIds))) {
            $validator->errors()->add('workflow.nodes', 'One or more selected sequences do not belong to your account.');
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
}
