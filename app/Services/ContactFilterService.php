<?php

namespace App\Services;

use App\Enums\FilterCategory;
use App\Enums\FilterConnector;
use App\Enums\FilterMode;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Support\Filters\FilterSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Applies stored audience filters to a contacts query. The filters come from
 * storage without validation, so a missing or unknown value never makes the
 * audience larger: an unknown category, mode, operator, window or field
 * matches no contact.
 *
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class ContactFilterService
{
    /**
     * @param  Builder<Contact>  $query
     * @param  array<int, AudienceFilter>  $filters
     * @return Builder<Contact>
     */
    public function applyFiltersToQuery(Builder $query, array $filters): Builder
    {
        $filters = collect($filters)->values();

        if ($filters->isEmpty()) {
            return $query;
        }

        $query->where(function (Builder $q) use ($filters): void {
            $isFirst = true;

            foreach ($filters as $filter) {
                $connector = FilterConnector::read($filter['connector'] ?? null);
                $method = (! $isFirst && $connector === FilterConnector::Or) ? 'orWhere' : 'where';

                $q->{$method}(function (Builder $subQ) use ($filter): void {
                    $this->applyFilterToQuery($subQ, $filter);
                });

                $isFirst = false;
            }
        });

        return $query;
    }

    /**
     * @param  array<int, AudienceFilter>  $filters
     */
    public function contactMatchesFilters(Contact $contact, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        return Contact::query()
            ->where('id', $contact->id)
            ->where(function (Builder $q) use ($filters): void {
                $this->applyFiltersToQuery($q, $filters);
            })
            ->exists();
    }

    /**
     * Build the recipients query for a newsletter.
     *
     * @param  array<int, AudienceFilter>  $filters
     * @return Builder<Contact>
     */
    public function newsletterRecipientsQuery(int $clientId, array $filters, ?Newsletter $newsletter = null): Builder
    {
        $query = Contact::query()->sendable($clientId);

        if ($newsletter !== null) {
            $query->whereDoesntHave('mails', fn (Builder $q) => $q
                ->where('mailable_type', Newsletter::class)
                ->where('mailable_id', $newsletter->id)
            );
        }

        return $this->applyFiltersToQuery($query, $filters);
    }

    /**
     * Whether a single address is suppressed for this client. Used by the
     * sequence sender, which walks due mails one at a time.
     */
    public function isBlacklisted(string $email, int $clientId): bool
    {
        return BlacklistedEmail::withoutGlobalScopes()
            ->where('email', $email)
            ->where(function (Builder $scope) use ($clientId): void {
                $scope->where('client_id', $clientId)
                    ->orWhereNull('client_id');
            })
            ->exists();
    }

    /**
     * Count the contacts a newsletter would send to.
     *
     * @param  array<int, AudienceFilter>  $filters
     */
    public function recipientCount(int $clientId, array $filters, ?Newsletter $newsletter = null): int
    {
        return $this->newsletterRecipientsQuery($clientId, $filters, $newsletter)->count();
    }

    /**
     * Build the contacts query for a saved segment.
     *
     * Unlike newsletterRecipientsQuery this deliberately INCLUDES unsubscribed
     * contacts: a segment is a pure audience predicate, and send-context
     * exclusions (unsubscribed, already-sent) are layered on separately at send
     * time. Empty filters match every contact for the client.
     *
     * @param  array<int, AudienceFilter>  $filters
     * @return Builder<Contact>
     */
    public function segmentContactsQuery(int $clientId, array $filters): Builder
    {
        $query = Contact::query()->where('client_id', $clientId);

        return $this->applyFiltersToQuery($query, $filters);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyFilterToQuery(Builder $query, array $filter): void
    {
        $category = FilterCategory::fromStored($filter['category'] ?? null);

        if ($category === FilterCategory::Date) {
            $this->applyDateFilterToQuery($query, $filter);

            return;
        }

        if ($category === FilterCategory::Engagement) {
            $this->applyEngagementFilterToQuery($query, $filter);

            return;
        }

        $mode = $category?->storedMode($filter['mode'] ?? null);

        if ($category === null || $mode === null) {
            $this->matchNoContact($query);

            return;
        }

        match ($category) {
            FilterCategory::Attributes => $this->applyAttributeFilterToQuery($query, $filter, $mode),
            FilterCategory::Tags => $this->applyTagFilterToQuery($query, $filter, $mode),
            FilterCategory::Lists => $this->applyListFilterToQuery($query, $filter, $mode),
            FilterCategory::Topics => $this->applyTopicFilterToQuery($query, $filter, $mode),
            FilterCategory::Location => $this->applyLocationFilterToQuery($query, $filter, $mode),
        };
    }

    /**
     * Fail closed: a filter that the app cannot read matches no contact.
     *
     * @param  Builder<Contact>  $query
     */
    private function matchNoContact(Builder $query): void
    {
        $query->whereRaw('0 = 1');
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyAttributeFilterToQuery(Builder $query, array $filter, FilterMode $mode): void
    {
        $values = collect($filter['values'] ?? [])
            ->filter(fn ($value) => is_array($value) && isset($value['key']) && $value['key'] !== '')
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        if ($values->contains(fn (array $value): bool => $this->attributeOperator($value) === null)) {
            $this->matchNoContact($query);

            return;
        }

        if ($mode === FilterMode::Any) {
            $query->where(function (Builder $q) use ($values): void {
                foreach ($values as $value) {
                    $q->orWhere(fn (Builder $sub) => $this->applyAttributeCondition($sub, $value, false));
                }
            });

            return;
        }

        // all / none: each condition is ANDed; none negates every condition.
        $negate = $mode === FilterMode::None;

        foreach ($values as $value) {
            $query->where(fn (Builder $sub) => $this->applyAttributeCondition($sub, $value, $negate));
        }
    }

    /**
     * Apply a single attribute condition, optionally negated for "none" mode.
     *
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $value
     */
    private function applyAttributeCondition(Builder $query, array $value, bool $negate): void
    {
        $key = (string) $value['key'];
        $operator = $this->attributeOperator($value);
        $raw = isset($value['value']) ? (string) $value['value'] : '';

        // Operators split into a base operator plus an inherent negation,
        // which is then XOR-combined with the filter "none" mode negation.
        [$base, $inherentNegate] = match ($operator) {
            'not_equals' => ['equals', true],
            'not_contains' => ['contains', true],
            'is_not_set' => ['is_set', true],
            default => [$operator, false],
        };

        $effectiveNegate = $negate !== $inherentNegate;
        $column = 'extra_attributes->'.$key;

        match ($base) {
            'is_set' => $effectiveNegate
                ? $query->whereNull($column)
                : $query->whereNotNull($column),
            'contains' => $effectiveNegate
                ? $query->where(fn (Builder $q) => $q
                    ->whereNull($column)
                    ->orWhere($column, 'not like', '%'.$raw.'%'))
                : $query->where($column, 'like', '%'.$raw.'%'),
            'gt' => $this->applyNumericAttributeCondition($query, $key, '>', $raw, $effectiveNegate),
            'lt' => $this->applyNumericAttributeCondition($query, $key, '<', $raw, $effectiveNegate),
            default => $effectiveNegate
                ? $query->where(fn (Builder $q) => $q
                    ->whereNull($column)
                    ->orWhere($column, '!=', $raw))
                : $query->where($column, $raw),
        };
    }

    /**
     * Read the operator of one stored attribute condition. A condition with
     * no operator uses "equals". An unknown operator gives null.
     *
     * @param  array<string, mixed>  $value
     */
    private function attributeOperator(array $value): ?string
    {
        $operator = $value['operator'] ?? 'equals';

        return in_array($operator, FilterSchema::ATTRIBUTE_OPERATORS, true) ? $operator : null;
    }

    /**
     * Numeric comparison against a JSON attribute, casting the stored value.
     *
     * The JSON extraction expression is driver-aware (MySQL json_unquote vs
     * SQLite json_extract) via the grammar wrapper.
     *
     * @param  Builder<Contact>  $query
     */
    private function applyNumericAttributeCondition(Builder $query, string $key, string $operator, string $raw, bool $negate): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap('extra_attributes->'.$key);
        $cast = "CAST({$wrapped} AS DECIMAL(30, 10))";
        $number = (float) $raw;

        if ($negate) {
            $inverse = $operator === '>' ? '<=' : '>=';

            $query->where(function (Builder $q) use ($key, $cast, $inverse, $number): void {
                $q->whereNull('extra_attributes->'.$key)
                    ->orWhereRaw("{$cast} {$inverse} ?", [$number]);
            });

            return;
        }

        $query->whereRaw("{$cast} {$operator} ?", [$number]);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyTagFilterToQuery(Builder $query, array $filter, FilterMode $mode): void
    {
        $tagNames = collect($filter['values'] ?? [])
            ->filter()
            ->values()
            ->all();

        if (empty($tagNames)) {
            return;
        }

        if ($mode === FilterMode::None) {
            $query->whereDoesntHave('tags', fn (Builder $q) => $q->whereIn('name', $tagNames));
            $this->requireEachReferenceToExist($query, 'tags', 'name', $tagNames);
        } elseif ($mode === FilterMode::All) {
            foreach ($tagNames as $tagName) {
                $query->whereHas('tags', fn (Builder $q) => $q->where('name', $tagName));
            }
        } else {
            $query->whereHas('tags', fn (Builder $q) => $q->whereIn('name', $tagNames));
        }
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyListFilterToQuery(Builder $query, array $filter, FilterMode $mode): void
    {
        $listIds = collect($filter['values'] ?? [])
            ->filter(fn ($value) => is_numeric($value) && $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        if (empty($listIds)) {
            return;
        }

        if ($mode === FilterMode::None) {
            $query->whereDoesntHave('lists', fn (Builder $q) => $q->whereIn('lists.id', $listIds));
            $this->requireEachReferenceToExist($query, 'lists', 'id', $listIds);
        } elseif ($mode === FilterMode::All) {
            foreach ($listIds as $listId) {
                $query->whereHas('lists', fn (Builder $q) => $q->where('lists.id', $listId)->confirmed());
            }
        } else {
            $query->whereHas('lists', fn (Builder $q) => $q->whereIn('lists.id', $listIds)->confirmed());
        }
    }

    /**
     * Make a "none" filter fail closed when it names a tag, list or topic
     * that does not exist in the client of the contact.
     *
     * A tag, list or topic can be deleted or renamed after the filter is
     * saved. Its pivot rows are gone, so "none of X" would exclude nobody
     * and make the audience larger. With this check the filter matches no
     * contact until a user saves the filter again.
     *
     * @param  Builder<Contact>  $query
     * @param  array<int, int|string>  $references
     */
    private function requireEachReferenceToExist(Builder $query, string $table, string $column, array $references): void
    {
        foreach (array_unique($references) as $reference) {
            $query->whereExists(fn (QueryBuilder $exists) => $exists
                ->selectRaw('1')
                ->from($table)
                ->whereColumn("{$table}.client_id", 'contacts.client_id')
                ->where("{$table}.{$column}", $reference));
        }
    }

    /**
     * Match contacts by topic subscription state.
     *
     * Subscribed = contact_topic row exists with unsubscribed_at IS NULL.
     *
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyTopicFilterToQuery(Builder $query, array $filter, FilterMode $mode): void
    {
        $topicIds = collect($filter['values'] ?? [])
            ->filter(fn ($value) => is_numeric($value) && $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        if (empty($topicIds)) {
            return;
        }

        if ($mode === FilterMode::None) {
            $query->whereDoesntHave('topics', fn (Builder $q) => $q
                ->whereIn('topics.id', $topicIds)
                ->whereNull('contact_topic.unsubscribed_at'));
            $this->requireEachReferenceToExist($query, 'topics', 'id', $topicIds);
        } elseif ($mode === FilterMode::All) {
            foreach ($topicIds as $topicId) {
                $query->whereHas('topics', fn (Builder $q) => $q
                    ->where('topics.id', $topicId)
                    ->whereNull('contact_topic.unsubscribed_at'));
            }
        } else {
            $query->whereHas('topics', fn (Builder $q) => $q
                ->whereIn('topics.id', $topicIds)
                ->whereNull('contact_topic.unsubscribed_at'));
        }
    }

    /**
     * Match contacts by their created_at date. An unknown operator, or an
     * operator without its date or day count, matches no contact.
     *
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyDateFilterToQuery(Builder $query, array $filter): void
    {
        $operator = $filter['operator'] ?? null;
        $values = $filter['values'] ?? [];
        $days = $this->storedDays($values['days'] ?? null);

        $applied = match ($operator) {
            'before' => isset($values['date'])
                ? $query->whereDate('created_at', '<', $values['date'])
                : null,
            'after' => isset($values['date'])
                ? $query->whereDate('created_at', '>', $values['date'])
                : null,
            'in_last' => $days !== null
                ? $query->where('created_at', '>=', Carbon::now()->subDays($days))
                : null,
            'more_than' => $days !== null
                ? $query->where('created_at', '<', Carbon::now()->subDays($days))
                : null,
            'between' => (isset($values['from'], $values['to']))
                ? $query->where('created_at', '>=', $values['from'].' 00:00:00')
                    ->where('created_at', '<', Carbon::parse($values['to'])->addDay()->startOfDay())
                : null,
            default => null,
        };

        if ($applied === null) {
            $this->matchNoContact($query);
        }
    }

    /**
     * Read a stored day count. A count that is not a number, or is less
     * than one day, gives null.
     */
    private function storedDays(mixed $days): ?int
    {
        if (! is_numeric($days) || (int) $days < FilterSchema::MIN_DAYS) {
            return null;
        }

        return (int) $days;
    }

    /**
     * Match contacts by newsletter engagement (delivered / opened / clicked).
     *
     * `received` checks delivered_at — i.e. the newsletter actually reached the
     * contact's inbox, not merely that we attempted the send. A bounced send is
     * not "received".
     *
     * Negated operators (not_opened, not_clicked, not_received) match contacts
     * with no qualifying ContactMail row — this deliberately INCLUDES contacts
     * who never had a newsletter delivered at all (cold or bounced contacts),
     * so re-engagement filters can reach them. Scope to a specific newsletter
     * to narrow that.
     *
     * An unknown operator, an unknown window, a last_n_days window without a
     * day count, or a newsletter id that is not a number matches no contact.
     * The negated operators fail closed too, so they never match everyone.
     *
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyEngagementFilterToQuery(Builder $query, array $filter): void
    {
        $operator = $filter['operator'] ?? null;
        $values = $filter['values'] ?? [];
        $storedNewsletterId = $values['newsletter_id'] ?? null;
        $within = $values['within'] ?? 'ever';
        $days = $this->storedDays($values['days'] ?? null);

        if (! in_array($operator, FilterSchema::ENGAGEMENT_OPERATORS, true)
            || ! in_array($within, FilterSchema::ENGAGEMENT_WINDOWS, true)
            || ($within === 'last_n_days' && $days === null)
            || ($storedNewsletterId !== null && ! is_numeric($storedNewsletterId))) {
            $this->matchNoContact($query);

            return;
        }

        $negate = str_starts_with($operator, 'not_');
        $base = $negate ? substr($operator, 4) : $operator;

        $timeColumn = match ($base) {
            'opened' => 'opened_at',
            'clicked' => 'clicked_at',
            default => 'delivered_at',
        };

        $newsletterId = $storedNewsletterId !== null ? (int) $storedNewsletterId : null;

        $constraint = function (Builder $q) use ($timeColumn, $newsletterId, $within, $days): void {
            // withoutGlobalScopes keeps engagement matching correct in queued
            // send context where there is no authenticated user; the outer
            // query is already pinned to a single client.
            $q->withoutGlobalScopes()
                ->where('mailable_type', Newsletter::class)
                ->whereNotNull($timeColumn);

            if ($newsletterId !== null) {
                $q->where('mailable_id', $newsletterId);
            }

            if ($within === 'last_n_days') {
                $q->where($timeColumn, '>=', Carbon::now()->subDays($days));
            }
        };

        if ($negate) {
            $query->whereDoesntHave('mails', $constraint);
        } else {
            $query->whereHas('mails', $constraint);
        }
    }

    /**
     * Match contacts by country or timezone. A filter with no field uses the
     * country. An unknown field, or a mode that a location filter does not
     * support, matches no contact.
     *
     * @param  Builder<Contact>  $query
     * @param  AudienceFilter  $filter
     */
    private function applyLocationFilterToQuery(Builder $query, array $filter, FilterMode $mode): void
    {
        $field = $filter['field'] ?? 'country';

        if (! in_array($field, FilterSchema::LOCATION_FIELDS, true)
            || ! in_array($mode, FilterMode::forLocation(), true)) {
            $this->matchNoContact($query);

            return;
        }

        $values = collect($filter['values'] ?? [])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        if (empty($values)) {
            return;
        }

        if ($mode === FilterMode::None) {
            $query->where(fn (Builder $q) => $q
                ->whereNull($field)
                ->orWhereNotIn($field, $values));

            return;
        }

        $query->whereIn($field, $values);
    }
}
