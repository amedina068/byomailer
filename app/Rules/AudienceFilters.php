<?php

namespace App\Rules;

use App\Enums\FilterCategory;
use App\Enums\FilterMode;
use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\Topic;
use App\Support\Filters\FilterSchema;
use App\Support\Tenant;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * The audience filter rules of the web dashboard, the API and the MCP server.
 *
 * Each check matches one case that FilterPreparationService::prepareFilters
 * drops. A filter that passes this rule reaches storage unchanged, so no
 * channel drops a filter and widens the audience. The tag, list, topic,
 * attribute and newsletter checks run in the current tenant.
 */
class AudienceFilters implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    public function setValidator($validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->addErrors($this->validator, $attribute, $value);
    }

    /**
     * Add an error to the validator for each invalid filter in the array.
     * The error key is "<attribute>.<index>.<field>".
     */
    public function addErrors(Validator $validator, string $attribute, mixed $filters): void
    {
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $index => $filter) {
            if (! is_array($filter) || ! isset($filter['category'])) {
                continue;
            }

            $key = "{$attribute}.{$index}";
            $category = is_string($filter['category']) ? FilterCategory::tryFrom($filter['category']) : null;

            if ($category === null) {
                continue;
            }

            $this->validateCategoryShape($validator, $key, $category, $filter);

            $values = $filter['values'] ?? null;

            if (! is_array($values) || empty($values)) {
                continue;
            }

            match ($category) {
                FilterCategory::Tags => $this->validateTagsExist($validator, $key, $values),
                FilterCategory::Lists => $this->validateIdsExist($validator, $key, $values, MailList::class, 'list'),
                FilterCategory::Topics => $this->validateIdsExist($validator, $key, $values, Topic::class, 'topic'),
                FilterCategory::Attributes => $this->validateAttributes($validator, $key, $values),
                FilterCategory::Date => $this->validateDateValues($validator, $key, $filter['operator'] ?? null, $values),
                FilterCategory::Engagement => $this->validateEngagementValues($validator, $key, $values),
                FilterCategory::Location => $this->validateLocationValues($validator, $key, $values),
            };
        }
    }

    /**
     * Enforce the operator / mode rules that differ per category.
     *
     * @param  array<string, mixed>  $filter
     */
    private function validateCategoryShape(Validator $validator, string $key, FilterCategory $category, array $filter): void
    {
        $mode = is_string($filter['mode'] ?? null) ? FilterMode::tryFrom($filter['mode']) : null;

        if ($category->hasMode()) {
            if ($mode === null) {
                $validator->errors()->add("{$key}.mode", 'A match mode is required for this filter.');
            }

            return;
        }

        if ($category === FilterCategory::Location) {
            if (! in_array($filter['field'] ?? null, FilterSchema::LOCATION_FIELDS, true)) {
                $validator->errors()->add("{$key}.field", 'Choose a valid location field.');
            }

            if (! in_array($mode, FilterMode::forLocation(), true)) {
                $validator->errors()->add("{$key}.mode", 'Location filters support "any" or "none" only.');
            }

            return;
        }

        if (! in_array($filter['operator'] ?? null, FilterSchema::operatorsFor($category), true)) {
            $validator->errors()->add(
                "{$key}.operator",
                'The selected operator is not valid for a '.$category->value.' filter.'
            );
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function validateTagsExist(Validator $validator, string $key, array $values): void
    {
        $names = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $names[] = trim($value);

                continue;
            }

            $validator->errors()->add("{$key}.values", 'Choose at least one tag.');
        }

        if (empty($names)) {
            return;
        }

        $existing = Tag::query()->whereIn('name', $names)->pluck('name')->all();

        foreach (array_diff($names, $existing) as $name) {
            $validator->errors()->add("{$key}.values", "The tag \"{$name}\" does not exist.");
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  class-string<MailList|Topic>  $model
     */
    private function validateIdsExist(Validator $validator, string $key, array $values, string $model, string $label): void
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;

                continue;
            }

            $validator->errors()->add("{$key}.values", "Choose at least one {$label}.");
        }

        if (empty($ids)) {
            return;
        }

        $existing = $model::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach (array_diff($ids, $existing) as $id) {
            $validator->errors()->add("{$key}.values", "The {$label} with ID {$id} does not exist.");
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function validateAttributes(Validator $validator, string $key, array $values): void
    {
        $names = [];

        foreach ($values as $value) {
            $error = $this->attributeValueError($value);

            if ($error !== null) {
                $validator->errors()->add("{$key}.values", $error);

                continue;
            }

            $names[] = trim($value['key']);
        }

        $names = array_values(array_unique($names));

        if (empty($names)) {
            return;
        }

        $existing = ContactAttribute::query()->whereIn('name', $names)->pluck('name')->all();

        foreach (array_diff($names, $existing) as $name) {
            $validator->errors()->add("{$key}.values", "The attribute \"{$name}\" does not exist.");
        }
    }

    private function attributeValueError(mixed $value): ?string
    {
        if (! is_array($value) || ! isset($value['key']) || ! is_string($value['key']) || trim($value['key']) === '') {
            return 'Choose an attribute for this filter.';
        }

        $name = trim($value['key']);

        if (! preg_match('/^[\p{L}\p{N} ._-]+$/u', $name)) {
            return "The attribute \"{$name}\" has characters that a filter cannot use.";
        }

        $operator = $value['operator'] ?? 'equals';

        if (! in_array($operator, FilterSchema::ATTRIBUTE_OPERATORS, true)) {
            return 'The selected operator is not valid for an attribute filter.';
        }

        if (isset($value['value']) && ! is_string($value['value'])) {
            return 'Attribute "value" must be a string when provided.';
        }

        if (in_array($operator, FilterSchema::VALUELESS_ATTRIBUTE_OPERATORS, true)) {
            return null;
        }

        $raw = $value['value'] ?? '';

        if ($raw === '') {
            return "Enter a value for the attribute \"{$name}\".";
        }

        if (in_array($operator, ['gt', 'lt'], true) && ! is_numeric($raw)) {
            return "Attribute \"{$name}\" needs a number for this operator.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function validateDateValues(Validator $validator, string $key, mixed $operator, array $values): void
    {
        if (! in_array($operator, FilterSchema::DATE_OPERATORS, true)) {
            return;
        }

        if (in_array($operator, FilterSchema::RELATIVE_DATE_OPERATORS, true)) {
            if (! $this->validDays($values['days'] ?? null)) {
                $validator->errors()->add("{$key}.values", $this->daysMessage());
            }

            return;
        }

        if ($operator !== 'between') {
            if (! $this->validDate($values['date'] ?? null)) {
                $validator->errors()->add("{$key}.values", 'Enter a valid date in the YYYY-MM-DD format.');
            }

            return;
        }

        $from = $values['from'] ?? null;
        $to = $values['to'] ?? null;

        if (! $this->validDate($from) || ! $this->validDate($to)) {
            $validator->errors()->add("{$key}.values", 'Enter a valid date in the YYYY-MM-DD format.');

            return;
        }

        if ($from > $to) {
            $validator->errors()->add("{$key}.values", 'The start date must be on or before the end date.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function validateEngagementValues(Validator $validator, string $key, array $values): void
    {
        $within = $values['within'] ?? 'ever';

        if (! in_array($within, FilterSchema::ENGAGEMENT_WINDOWS, true)) {
            $validator->errors()->add("{$key}.values", 'Choose a valid time window.');
        }

        if ($within === 'last_n_days' && ! $this->validDays($values['days'] ?? null)) {
            $validator->errors()->add("{$key}.values", $this->daysMessage());
        }

        if (! isset($values['newsletter_id'])) {
            return;
        }

        // Pin the newsletter to the tenant explicitly. A filter must never
        // reference the newsletter of another client.
        $clientId = Tenant::id();

        if (! is_numeric($values['newsletter_id'])
            || ! Newsletter::query()
                ->whereKey((int) $values['newsletter_id'])
                ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
                ->exists()) {
            $validator->errors()->add("{$key}.values", 'The selected newsletter does not exist.');
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function validateLocationValues(Validator $validator, string $key, array $values): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                $validator->errors()->add("{$key}.values", 'Choose at least one location.');

                return;
            }
        }
    }

    private function validDays(mixed $value): bool
    {
        return is_numeric($value)
            && (int) $value >= FilterSchema::MIN_DAYS
            && (int) $value <= FilterSchema::MAX_DAYS;
    }

    private function daysMessage(): string
    {
        return 'Enter a number of days from '.FilterSchema::MIN_DAYS.' to '.FilterSchema::MAX_DAYS.'.';
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
