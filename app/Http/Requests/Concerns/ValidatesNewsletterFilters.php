<?php

namespace App\Http\Requests\Concerns;

use App\Enums\FilterCategory;
use App\Enums\FilterConnector;
use App\Enums\FilterMode;
use App\Rules\AudienceFilters;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

trait ValidatesNewsletterFilters
{
    /**
     * Shared validation rules for the `filters.*` array items.
     *
     * @return array<string, array<int, string|Enum>>
     */
    protected function filterItemRules(): array
    {
        return [
            'filters.*' => ['array'],
            'filters.*.connector' => ['sometimes', Rule::enum(FilterConnector::class)],
            'filters.*.category' => ['required_with:filters.*', Rule::enum(FilterCategory::class)],
            'filters.*.mode' => ['sometimes', Rule::enum(FilterMode::class)],
            'filters.*.operator' => ['sometimes', 'string'],
            'filters.*.field' => ['sometimes', 'string'],
            'filters.*.values' => ['required_with:filters.*', 'array'],
        ];
    }

    /**
     * Run the shared audience filter checks. The MCP tools run the same
     * checks through the AudienceFilters rule.
     */
    protected function validateFilterOwnership(Validator $validator): void
    {
        (new AudienceFilters)->addErrors($validator, 'filters', $this->validated('filters', []));
    }
}
