<?php

namespace App\Models;

use App\Enums\SequenceStatus;
use App\Events\ContactAttributesChanged;
use App\Events\ContactListsSynced;
use App\Events\ContactSequencesSynced;
use App\Events\ContactTagsSynced;
use App\Jobs\SendOptInEmailJob;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use App\Services\ContactFilterService;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property array<array-key, mixed>|null $extra_attributes
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * When true, system-initiated unsubscribe transitions (SES bounce/complaint)
     * skip dispatching the ContactUnsubscribed webhook event. Not persisted.
     */
    public bool $webhooksSuppressed = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_attributes' => 'array',
            'unsubscribed_at' => 'datetime',
            'unsubscribed_by_contact' => 'boolean',
            'soft_bounce_count' => 'integer',
            'last_engaged_at' => 'datetime',
            'sunset_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Contact $contact): void {
            $contact->dispatchAttributeChanges();
        });
    }

    /**
     * Runs inside the `saved` event, before `syncOriginal()`, so the original
     * attributes still hold the values from before this save.
     */
    private function dispatchAttributeChanges(): void
    {
        if (! $this->isDirty('extra_attributes')) {
            return;
        }

        $changes = $this->attributeChanges(
            (array) $this->getOriginal('extra_attributes'),
            (array) $this->extra_attributes,
        );

        if (! $changes) {
            return;
        }

        if ($this->hasLiveAutomationsFor(['attribute_changed'])) {
            ContactAttributesChanged::dispatch($this, $changes);
        }
    }

    /**
     * Whether the client of this contact has a live visual automation. The
     * enrollment listeners read the trigger of each one.
     */
    private function hasLiveVisualAutomations(): bool
    {
        return VisualAutomation::withoutGlobalScopes()
            ->where('client_id', $this->client_id)
            ->live()
            ->exists();
    }

    /**
     * Whether the client of this contact has a live simple automation with one of these condition types.
     *
     * @param  list<string>  $conditionTypes
     */
    private function hasLiveAutomationsFor(array $conditionTypes): bool
    {
        return Automation::withoutGlobalScopes()
            ->where('client_id', $this->client_id)
            ->live()
            ->where('type', Automation::TYPE_SIMPLE)
            ->whereIn('condition->type', $conditionTypes)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{old: ?string, new: ?string}>
     */
    private function attributeChanges(array $old, array $new): array
    {
        $changes = [];

        foreach (array_keys($old + $new) as $key) {
            $oldValue = self::normalizeAttributeValue($old[$key] ?? null);
            $newValue = self::normalizeAttributeValue($new[$key] ?? null);

            if ($oldValue !== $newValue) {
                $changes[(string) $key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    /**
     * Normalize an attribute value for change comparison: missing and blank become null,
     * scalars become trimmed strings, and arrays become JSON.
     */
    private static function normalizeAttributeValue(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<MailList, $this>
     */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(MailList::class, 'contact_list', 'contact_id', 'list_id')
            ->withPivot('opted_in_at')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'contact_topic')
            ->withPivot(['subscribed_at', 'unsubscribed_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Sequence, $this>
     */
    public function sequences(): BelongsToMany
    {
        return $this->belongsToMany(Sequence::class)->withTimestamps();
    }

    /**
     * @return HasMany<ContactMail, $this>
     */
    public function mails(): HasMany
    {
        return $this->hasMany(ContactMail::class);
    }

    /**
     * @return HasMany<VisualAutomationEnrollment, $this>
     */
    public function visualAutomationEnrollments(): HasMany
    {
        return $this->hasMany(VisualAutomationEnrollment::class);
    }

    /**
     * @return HasManyThrough<VisualAutomationContactStep, VisualAutomationEnrollment, $this>
     */
    public function visualAutomationSteps(): HasManyThrough
    {
        return $this->hasManyThrough(
            VisualAutomationContactStep::class,
            VisualAutomationEnrollment::class,
            'contact_id',
            'enrollment_id',
        );
    }

    /**
     * @param  array<int, int>|array<int, array<string, mixed>>  $lists
     * @return array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}
     */
    public function syncLists(array $lists, bool $withoutDetaching = false): array
    {
        $result = $withoutDetaching
            ? $this->lists()->syncWithoutDetaching($lists)
            : $this->lists()->sync($lists);

        $attached = $result['attached'] ?? [];
        if (! empty($attached)) {
            $this->joinLists($attached);
        }

        $this->dispatchListsSynced($result);

        return $result;
    }

    /**
     * Remove the contact from these lists. The other lists stay. Like
     * syncLists(), it fires `ContactListsSynced` for the list automations
     * and the sequence list filters.
     *
     * @param  array<int, int>  $listIds
     * @return array{attached: array<int, int>, detached: array<int, int>, updated: array<int, int>}
     */
    public function detachLists(array $listIds): array
    {
        $detached = $this->lists()
            ->withoutGlobalScopes()
            ->whereKey($listIds)
            ->pluck('lists.id')
            ->all();

        $result = ['attached' => [], 'detached' => $detached, 'updated' => []];

        if ($detached === []) {
            return $result;
        }

        $this->lists()->detach($detached);
        $this->dispatchListsSynced($result);

        return $result;
    }

    /**
     * @param  array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}  $result
     */
    private function dispatchListsSynced(array $result): void
    {
        $hasRelevantAutomations = $this->hasLiveAutomationsFor(['contact_added_to_list', 'removed_from_list'])
            || $this->hasLiveVisualAutomations();

        $hasSequencesWithListFilters = $this->sequences()
            ->where('status', SequenceStatus::Live)
            ->withAudienceRules()
            ->exists();

        if ($hasRelevantAutomations || $hasSequencesWithListFilters) {
            ContactListsSynced::dispatch($this, $result);
        }
    }

    /**
     * Subscribe the contact to the topics of each new list. A list with opt-in
     * gets the opt-in email instead, and its topics wait for the confirmation.
     *
     * @param  array<int, int>  $listIds
     */
    private function joinLists(array $listIds): void
    {
        $lists = MailList::withoutGlobalScopes()->whereIn('id', $listIds)->get();

        [$optInLists, $openLists] = $lists->partition(fn (MailList $list): bool => $list->opt_in_enabled);

        $this->subscribeToListTopics($openLists->modelKeys());

        $optInLists->each(fn (MailList $list) => SendOptInEmailJob::dispatch($this, $list)->afterCommit());
    }

    /**
     * @param  array<int, int>|array<int, array<string, mixed>>  $tags
     * @return array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}
     */
    public function syncTags(array $tags, bool $withoutDetaching = false): array
    {
        $result = $withoutDetaching
            ? $this->tags()->syncWithoutDetaching($tags)
            : $this->tags()->sync($tags);

        $hasRelevantAutomations = $this->hasLiveAutomationsFor(['tag_added', 'removed_tag'])
            || $this->hasLiveVisualAutomations();

        $hasSequencesWithTagFilters = $this->sequences()
            ->where('status', SequenceStatus::Live)
            ->withAudienceRules()
            ->exists();

        if ($hasRelevantAutomations || $hasSequencesWithTagFilters) {
            ContactTagsSynced::dispatch($this, $result);
        }

        return $result;
    }

    /**
     * @param  array<int, int>|array<int, array<string, mixed>>  $sequences
     * @return array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}
     */
    public function syncSequences(array $sequences, bool $withoutDetaching = false): array
    {
        if (! empty($sequences)) {
            $filterService = app(ContactFilterService::class);

            $sequences = Sequence::query()
                ->whereIn('id', $sequences)
                ->where('status', SequenceStatus::Live)
                ->with(['segment' => fn ($q) => $q->withoutGlobalScopes()])
                ->get()
                ->filter(function (Sequence $sequence) use ($filterService): bool {
                    $filters = $sequence->effectiveFilters();

                    return empty($filters) || $filterService->contactMatchesFilters($this, $filters);
                })
                ->pluck('id')
                ->values()
                ->all();
        }

        $result = $withoutDetaching
            ? $this->sequences()->syncWithoutDetaching($sequences)
            : $this->sequences()->sync($sequences);

        $attached = $result['attached'] ?? [];

        if (! $attached) {
            return $result;
        }

        $automations = Automation::withoutGlobalScopes()
            ->where('client_id', $this->client_id)
            ->live()
            ->where('type', 'simple')
            ->where('condition->type', 'added_to_sequence')
            ->whereIn('condition->value', $attached)
            ->get();

        ContactSequencesSynced::dispatch($this, $result, $automations);

        return $result;
    }

    /**
     * Auto-subscribe this contact to every topic belonging to the given lists.
     * Existing pivot rows are left untouched so prior unsubscribes are preserved.
     *
     * @param  array<int, int>  $listIds
     */
    public function subscribeToListTopics(array $listIds): void
    {
        if (empty($listIds)) {
            return;
        }

        $topicIds = Topic::withoutGlobalScopes()
            ->whereIn('list_id', $listIds)
            ->pluck('id')
            ->all();

        if (empty($topicIds)) {
            return;
        }

        $now = now();
        $rows = array_map(fn (int $topicId): array => [
            'contact_id' => $this->id,
            'topic_id' => $topicId,
            'subscribed_at' => $now,
            'unsubscribed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $topicIds);

        DB::table('contact_topic')->insertOrIgnore($rows);
    }

    public function syncAttributeDefinitions(): void
    {
        if (! $this->user_id || ! $this->client_id) {
            return;
        }

        $attributes = collect($this->extra_attributes ?? [])
            ->keys()
            ->map(fn ($key) => is_string($key) ? trim($key) : null)
            ->filter()
            ->unique();

        $attributes->each(function (string $name): void {
            ContactAttribute::withoutGlobalScopes()->firstOrCreate(
                ['client_id' => $this->client_id, 'name' => $name],
                ['user_id' => $this->user_id],
            );
        });
    }

    /**
     * True when BYOMailer must not mail this contact: the contact unsubscribed,
     * or the sunset sweep marked it inactive.
     */
    /**
     * The suppression row of this address, from the client or global. When
     * there are more rows, a row that the client cannot remove comes first.
     */
    public function suppression(): ?BlacklistedEmail
    {
        $rows = BlacklistedEmail::withoutGlobalScopes()
            ->where('email', Str::lower($this->email))
            ->where(fn (Builder $query) => $query->where('client_id', $this->client_id)->orWhereNull('client_id'))
            ->get();

        return $rows->first(fn (BlacklistedEmail $row): bool => ! $row->isRemovable()) ?? $rows->first();
    }

    public function isExcludedFromSending(): bool
    {
        return $this->unsubscribed_at !== null || $this->sunset_at !== null;
    }

    /**
     * Contacts that BYOMailer may mail: not unsubscribed and not sunset.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeMailable(Builder $query): void
    {
        $query->whereNull('unsubscribed_at')->whereNull('sunset_at');
    }

    /**
     * Contacts of one client that BYOMailer may mail now: mailable, and the
     * address is not suppressed. A suppressed address is on the blacklist of
     * the client, or on the global blacklist (a hard bounce or a complaint
     * from any account).
     *
     * The newsletter recipient query and each newsletter chunk use this rule.
     * A chunk can wait for hours in the rate limiter, so it checks again
     * before it sends.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeSendable(Builder $query, int $clientId): void
    {
        $query->where('contacts.client_id', $clientId)
            ->mailable()
            ->whereNotExists(function (QueryBuilder $sub) use ($clientId): void {
                $sub->from('blacklisted_emails')
                    ->whereColumn('blacklisted_emails.email', 'contacts.email')
                    ->where(function (QueryBuilder $scope) use ($clientId): void {
                        $scope->where('blacklisted_emails.client_id', $clientId)
                            ->orWhereNull('blacklisted_emails.client_id');
                    })
                    ->selectRaw('1');
            });
    }

    public function cancelPendingMailings(): void
    {
        ContactMail::query()
            ->where('contact_id', $this->id)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->whereNull('unsubscribed_at')
            ->delete();
    }

    /**
     * Resolve a templated attribute for rendering.
     *
     * Lookup order: contacts table column, extra_attributes JSON, provided default, empty string.
     * Never returns null to prevent rendering errors.
     */
    public function getTemplateAttribute(string $attribute, string $default = ''): string
    {
        $key = trim($attribute);

        if ($key === '') {
            return $default;
        }

        $columnValue = $this->getAttributeValue($key);
        if ($columnValue !== null && $columnValue !== '' && ! is_array($columnValue)) {
            return (string) $columnValue;
        }

        $extra = $this->extra_attributes ?? [];
        if (Arr::has($extra, $key)) {
            $value = data_get($extra, $key);

            if (is_array($value)) {
                return json_encode($value);
            }

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return $default;
    }

    /**
     * The value of a variable for Liquid content. A list attribute stays a
     * list, so a `{% for %}` loop can read it. A missing or empty value is
     * null, because Liquid reads an empty string as true in `{% if %}`.
     * Other values are the text of getTemplateAttribute().
     *
     * @return string|array<mixed>|null
     */
    public function getTemplateValue(string $attribute): string|array|null
    {
        $key = trim($attribute);
        $column = $key === '' ? null : $this->getAttributeValue($key);
        $extra = data_get($this->extra_attributes ?? [], $key);

        if (($column === null || $column === '' || is_array($column)) && is_array($extra)) {
            return $extra;
        }

        $value = $this->getTemplateAttribute($attribute);

        return $value === '' ? null : $value;
    }
}
