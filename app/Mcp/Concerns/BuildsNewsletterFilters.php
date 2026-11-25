<?php

namespace App\Mcp\Concerns;

use App\Enums\FilterCategory;
use App\Enums\FilterConnector;
use App\Enums\FilterMode;
use App\Rules\AudienceFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait BuildsNewsletterFilters
{
    /**
     * Validation rules for the `filters` array. The AudienceFilters rule runs
     * the same tenant checks as the web dashboard and the API, so an invalid
     * filter returns an error and is never dropped.
     *
     * @return array<string, array<int, string|AudienceFilters|Enum>>
     */
    protected function filterValidationRules(): array
    {
        return [
            'filters' => ['sometimes', 'array', 'max:20', new AudienceFilters],
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
     * JSON Schema describing a single targeting filter. Use the
     * view-newsletter-filters-tool tool to discover the categories, operators and
     * concrete values (tags, lists, topics, attributes, …) available to the
     * authenticated account.
     *
     * @return array<string, mixed>
     */
    protected function filtersSchema(JsonSchema $schema, string $description = 'Audience targeting filters. Omit to target every active contact.'): array
    {
        return [
            'filters' => $schema->array()
                ->max(20)
                ->items($schema->object([
                    'connector' => $schema->string()
                        ->enum(array_column(FilterConnector::cases(), 'value'))
                        ->description('How this filter joins the previous one. Defaults to "and".'),
                    'category' => $schema->string()
                        ->enum(array_column(FilterCategory::cases(), 'value'))
                        ->description('The filter category.'),
                    'mode' => $schema->string()
                        ->enum(array_column(FilterMode::cases(), 'value'))
                        ->description('Match mode for attributes/tags/lists/topics (location uses any|none).'),
                    'operator' => $schema->string()
                        ->description('Operator for date or engagement filters.'),
                    'field' => $schema->string()
                        ->description('Field for location filters: country or timezone.'),
                    'values' => $schema->array()
                        ->description('Filter values. Shape depends on category — see view-newsletter-filters-tool.'),
                ]))
                ->description($description),
        ];
    }
}
