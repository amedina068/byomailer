<?php

namespace App\Concerns;

use App\Models\Tag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait TagValidationRules
{
    /**
     * Get the validation rules of a tag. The web form, the API and the MCP
     * tool use them. A name is unique in the client, because the tag filters
     * select tags by name.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function tagRules(int $clientId, ?Tag $tag = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Tag::class, 'name')
                    ->where('client_id', $clientId)
                    ->ignore($tag),
            ],
        ];
    }
}
