<?php

namespace App\Concerns;

use App\Models\Topic;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait TopicValidationRules
{
    /**
     * Get the validation rules of a topic. The web forms, the API and the MCP
     * tools use them. A name is unique in its list. A create has no topic to
     * ignore.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function topicRules(int $clientId, int $listId, int|string|null $ignoreTopicId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Topic::class, 'name')
                    ->where('list_id', $listId)
                    ->where('client_id', $clientId)
                    ->ignore($ignoreTopicId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
