<?php

namespace App\Services;

use App\Enums\FilterCategory;
use App\Enums\FilterConnector;
use App\Enums\FilterMode;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Topic;
use App\Models\User;
use App\Support\Filters\FilterSchema;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class FilterPreparationService
{
    /**
     * Sanitize raw filter input into a clean, storable structure.
     *
     * Each caller validates the input with the AudienceFilters rule first,
     * so this method drops nothing that a user sent. The drops here are a
     * last guard against unvalidated input.
     *
     * @param  array<array-key, mixed>  $filters
     * @return list<AudienceFilter>
     */
    public function prepareFilters(array $filters, User $user): array
    {
        return collect($filters)
            ->map(function (mixed $filter) use ($user): ?array {
                if (! is_array($filter) || ! is_string($filter['category'] ?? null)) {
                    return null;
                }

                $category = FilterCategory::tryFrom($filter['category']);

                if ($category === null) {
                    return null;
                }

                $prepared = match ($category) {
                    FilterCategory::Date => $this->prepareDateFilter($filter),
                    FilterCategory::Engagement => $this->prepareEngagementFilter($filter, $user),
                    FilterCategory::Location => $this->prepareLocationFilter($filter),
                    FilterCategory::Attributes,
                    FilterCategory::Tags,
                    FilterCategory::Lists,
                    FilterCategory::Topics => $this->prepareModeFilter($category, $filter, $user),
                };

                if ($prepared === null) {
                    return null;
                }

                return ['connector' => FilterConnector::read($filter['connector'] ?? null)->value] + $prepared;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Picker data for the engagement (newsletters) and location (country /
     * timezone) filters.
     *
     * @return array{
     *     newsletters: array<int, array{id: int, title: string}>,
     *     countries: array<int, string>,
     *     timezones: array<int, string>,
     * }
     */
    public function filterOptionData(int $clientId): array
    {
        return Cache::remember("filter-option-data:{$clientId}", 60, fn (): array => $this->buildFilterOptionData($clientId));
    }

    /**
     * @return array{
     *     newsletters: array<int, array{id: int, title: string}>,
     *     countries: array<int, string>,
     *     timezones: array<int, string>,
     * }
     */
    private function buildFilterOptionData(int $clientId): array
    {
        $newsletters = Newsletter::query()
            ->where('client_id', $clientId)
            ->where('title', '!=', '')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'title'])
            ->map(fn (Newsletter $newsletter): array => [
                'id' => $newsletter->id,
                'title' => $newsletter->title,
            ])
            ->all();

        $countries = Contact::query()
            ->where('client_id', $clientId)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->all();

        $timezones = Contact::query()
            ->where('client_id', $clientId)
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->distinct()
            ->orderBy('timezone')
            ->pluck('timezone')
            ->all();

        return [
            'newsletters' => $newsletters,
            'countries' => $countries,
            'timezones' => $timezones,
        ];
    }

    /**
     * Prepare a mode-based filter (attributes, tags, lists, topics).
     *
     * @param  AudienceFilter  $filter
     * @return AudienceFilter|null
     */
    private function prepareModeFilter(FilterCategory $category, array $filter, User $user): ?array
    {
        $mode = is_string($filter['mode'] ?? null) ? FilterMode::tryFrom($filter['mode']) : null;

        if ($mode === null) {
            return null;
        }

        $values = $this->sanitizeModeValues($category, $filter['values'] ?? [], $user);

        if (empty($values)) {
            return null;
        }

        return [
            'category' => $category->value,
            'mode' => $mode->value,
            'values' => $values,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<int, mixed>
     */
    private function sanitizeModeValues(FilterCategory $category, array $values, User $user): array
    {
        if ($category === FilterCategory::Attributes) {
            return $this->sanitizeAttributeValues($values);
        }

        if ($category === FilterCategory::Lists) {
            $ids = $this->numericIds($values);
            $valid = $user->client->mailingLists()->whereIn('id', $ids)->pluck('id')->all();

            return array_values(array_intersect($ids, $valid));
        }

        if ($category === FilterCategory::Topics) {
            $ids = $this->numericIds($values);
            $valid = Topic::query()
                ->where('client_id', $user->client_id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->all();

            return array_values(array_intersect($ids, $valid));
        }

        return collect($values)
            ->map(fn ($value) => is_string($value) ? trim($value) : null)
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, array<string, string>>
     */
    private function sanitizeAttributeValues(array $values): array
    {
        return collect($values)
            ->map(function ($value): ?array {
                if (! is_array($value)) {
                    return null;
                }

                $key = isset($value['key']) ? trim((string) $value['key']) : '';

                // Allowlist the key shape: it is interpolated into a JSON path
                // expression downstream. Quotes/backslashes would produce a
                // malformed path, so reject anything outside this safe set.
                if ($key === '' || ! preg_match('/^[\p{L}\p{N} ._-]+$/u', $key)) {
                    return null;
                }

                $operator = $value['operator'] ?? 'equals';

                if (! in_array($operator, FilterSchema::ATTRIBUTE_OPERATORS, true)) {
                    $operator = 'equals';
                }

                $valueless = in_array($operator, FilterSchema::VALUELESS_ATTRIBUTE_OPERATORS, true);
                $raw = isset($value['value']) ? (string) $value['value'] : '';

                if (! $valueless && $raw === '') {
                    return null;
                }

                if (in_array($operator, ['gt', 'lt'], true) && ! is_numeric($raw)) {
                    return null;
                }

                return [
                    'key' => $key,
                    'operator' => $operator,
                    'value' => $valueless ? '' : $raw,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  AudienceFilter  $filter
     * @return AudienceFilter|null
     */
    private function prepareDateFilter(array $filter): ?array
    {
        $operator = $filter['operator'] ?? null;

        if (! in_array($operator, FilterSchema::DATE_OPERATORS, true)) {
            return null;
        }

        $values = is_array($filter['values'] ?? null) ? $filter['values'] : [];
        $clean = null;

        if (in_array($operator, FilterSchema::RELATIVE_DATE_OPERATORS, true)) {
            $days = $this->boundedDays($values['days'] ?? null);
            $clean = $days !== null ? ['days' => $days] : null;
        } elseif ($operator === 'between') {
            $from = $this->validDate($values['from'] ?? null);
            $to = $this->validDate($values['to'] ?? null);
            $clean = ($from !== null && $to !== null && $from <= $to)
                ? ['from' => $from, 'to' => $to]
                : null;
        } else {
            $date = $this->validDate($values['date'] ?? null);
            $clean = $date !== null ? ['date' => $date] : null;
        }

        if ($clean === null) {
            return null;
        }

        return [
            'category' => FilterCategory::Date->value,
            'operator' => $operator,
            'values' => $clean,
        ];
    }

    /**
     * @param  AudienceFilter  $filter
     * @return AudienceFilter|null
     */
    private function prepareEngagementFilter(array $filter, User $user): ?array
    {
        $operator = $filter['operator'] ?? null;

        if (! in_array($operator, FilterSchema::ENGAGEMENT_OPERATORS, true)) {
            return null;
        }

        $values = is_array($filter['values'] ?? null) ? $filter['values'] : [];

        $within = in_array($values['within'] ?? null, FilterSchema::ENGAGEMENT_WINDOWS, true)
            ? $values['within']
            : 'ever';

        $days = null;

        if ($within === 'last_n_days') {
            $days = $this->boundedDays($values['days'] ?? null);

            if ($days === null) {
                return null;
            }
        }

        $newsletterId = null;

        if (isset($values['newsletter_id']) && is_numeric($values['newsletter_id'])) {
            $candidate = (int) $values['newsletter_id'];
            $exists = Newsletter::query()
                ->whereKey($candidate)
                ->where('client_id', $user->client_id)
                ->exists();
            $newsletterId = $exists ? $candidate : null;

            if (! $exists) {
                return null;
            }
        }

        return [
            'category' => FilterCategory::Engagement->value,
            'operator' => $operator,
            'values' => [
                'newsletter_id' => $newsletterId,
                'within' => $within,
                'days' => $days,
            ],
        ];
    }

    /**
     * @param  AudienceFilter  $filter
     * @return AudienceFilter|null
     */
    private function prepareLocationFilter(array $filter): ?array
    {
        $field = $filter['field'] ?? null;
        $mode = is_string($filter['mode'] ?? null) ? FilterMode::tryFrom($filter['mode']) : null;

        if (! in_array($field, FilterSchema::LOCATION_FIELDS, true)
            || ! in_array($mode, FilterMode::forLocation(), true)) {
            return null;
        }

        $values = collect(is_array($filter['values'] ?? null) ? $filter['values'] : [])
            ->map(fn ($value) => is_string($value) ? trim($value) : null)
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values()
            ->all();

        if (empty($values)) {
            return null;
        }

        return [
            'category' => FilterCategory::Location->value,
            'field' => $field,
            'mode' => $mode->value,
            'values' => $values,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function numericIds(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter(fn ($value) => $value !== null && $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function boundedDays(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        if ($days < FilterSchema::MIN_DAYS || $days > FilterSchema::MAX_DAYS) {
            return null;
        }

        return $days;
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }
}
