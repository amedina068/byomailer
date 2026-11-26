<?php

namespace App\Concerns;

use App\Models\Segment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait SegmentValidationRules
{
    /**
     * Get the validation rules of a segment. The web forms and the MCP tools
     * use them. A name is unique in the client. A segment needs 1 to 20
     * filters. The caller adds the rules of each filter item.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function segmentRules(int $clientId, ?Segment $segment = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Segment::class, 'name')
                    ->where('client_id', $clientId)
                    ->ignore($segment),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'filters' => ['present', 'array', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function segmentMessages(): array
    {
        return [
            'name.unique' => 'You already have a segment with this name.',
            'filters.min' => 'A segment needs at least one filter.',
        ];
    }
}
