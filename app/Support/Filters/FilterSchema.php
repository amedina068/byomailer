<?php

namespace App\Support\Filters;

use App\Enums\FilterCategory;

/**
 * Single source of truth for the operators of each newsletter and sequence
 * filter category, and for the operators that need a value. The enums
 * FilterCategory, FilterMode and FilterConnector hold the other values.
 *
 * Mirrored on the frontend in resources/js/types/newsletters.ts — keep both in sync.
 *
 * One audience filter, as FilterPreparationService::prepareFilters stores it.
 * A filter has "mode" when its category has a mode, and "operator" when its
 * category has an operator. Only a location filter has "field". A filter
 * that an old version of the app stored can miss a key, so each key is
 * optional, and readers use FilterCategory::fromStored() and tryFrom().
 *
 * @phpstan-type AudienceFilter array{
 *     connector?: string,
 *     category?: string,
 *     mode?: string,
 *     operator?: string,
 *     field?: string,
 *     values?: array<array-key, mixed>,
 * }
 */
class FilterSchema
{
    public const ATTRIBUTE_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'gt',
        'lt',
        'is_set',
        'is_not_set',
    ];

    /** Attribute operators that ignore the value field. */
    public const VALUELESS_ATTRIBUTE_OPERATORS = ['is_set', 'is_not_set'];

    public const DATE_OPERATORS = ['before', 'after', 'in_last', 'more_than', 'between'];

    /** Date operators backed by N-days input rather than calendar dates. */
    public const RELATIVE_DATE_OPERATORS = ['in_last', 'more_than'];

    public const ENGAGEMENT_OPERATORS = [
        'opened',
        'clicked',
        'received',
        'not_opened',
        'not_clicked',
        'not_received',
    ];

    public const ENGAGEMENT_WINDOWS = ['ever', 'last_n_days'];

    public const LOCATION_FIELDS = ['country', 'timezone'];

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 3650;

    /**
     * Operators valid for a given category. Empty for mode-based categories.
     *
     * @return array<int, string>
     */
    public static function operatorsFor(FilterCategory $category): array
    {
        return match ($category) {
            FilterCategory::Date => self::DATE_OPERATORS,
            FilterCategory::Engagement => self::ENGAGEMENT_OPERATORS,
            default => [],
        };
    }
}
