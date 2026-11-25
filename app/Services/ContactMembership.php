<?php

namespace App\Services;

use App\Actions\FindOrCreateTag;
use App\Events\ContactResubscribed;
use App\Events\ContactSubscribed;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactMembership
{
    public function __construct(private FindOrCreateTag $findOrCreateTag) {}

    /**
     * Save the changes. For an existing contact, the row is read again with
     * a lock, so `extra_attributes` merges into the current attributes and
     * `remove_attributes` removes keys from them.
     *
     * @param  array{
     *     email?: string,
     *     name?: string|null,
     *     tags?: array<int, string|null>,
     *     remove_tags?: array<int, string>,
     *     extra_attributes?: array<string, mixed>,
     *     remove_attributes?: array<int, string>,
     * }  $changes
     */
    public function persist(User $user, Contact $contact, array $changes): Contact
    {
        if ($contact->exists) {
            return DB::transaction(function () use ($user, $contact, $changes): Contact {
                $current = Contact::query()
                    ->where('client_id', $user->client_id)
                    ->whereKey($contact->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (array_key_exists('extra_attributes', $changes) || array_key_exists('remove_attributes', $changes)) {
                    $changes['extra_attributes'] = array_diff_key(
                        array_replace($current->extra_attributes ?? [], $changes['extra_attributes'] ?? []),
                        array_flip($changes['remove_attributes'] ?? []),
                    );
                }

                return $this->saveChanges($user, $current, $changes);
            }, attempts: 5);
        }

        $contact->forceFill(['user_id' => $user->id]);

        return $this->saveChanges($user, $contact, $changes);
    }

    /**
     * @param  array{email?: string, name?: string|null, tags?: array<int, string|null>, remove_tags?: array<int, string>, extra_attributes?: array<string, mixed>}  $changes
     */
    private function saveChanges(User $user, Contact $contact, array $changes): Contact
    {
        if (array_key_exists('email', $changes)) {
            $contact->email = $changes['email'];
        }

        if (array_key_exists('name', $changes)) {
            $contact->name = $changes['name'];
        }

        if (array_key_exists('extra_attributes', $changes)) {
            $contact->extra_attributes = $changes['extra_attributes'];
        }

        if ($this->isBlacklisted($user, (string) $contact->email)) {
            $contact->unsubscribed_at = $contact->unsubscribed_at ?? now();
            $contact->unsubscribed_by_contact = false;
        }

        $contact->save();

        if (! empty($changes['remove_tags'])) {
            $this->changeTags($user, $contact, array_values(array_filter($changes['tags'] ?? [], 'filled')), $changes['remove_tags']);
        } elseif (array_key_exists('tags', $changes)) {
            $this->attachTags($user, $contact, $changes['tags'] ?? []);
        }

        return $contact;
    }

    /**
     * Clear an unsubscribe that an admin made. An unsubscribe that the contact
     * made and a blacklisted address stay. Only the subscribe endpoints call
     * this. An add to a list never undoes an unsubscribe.
     *
     * The write runs only while `unsubscribed_by_contact` is still false in
     * the database. An unsubscribe click between the read and the write wins.
     * Returns false when the contact stays unsubscribed. The model then holds
     * the current row.
     */
    public function resubscribe(User $user, Contact $contact): bool
    {
        if ($contact->unsubscribed_at === null) {
            return true;
        }

        if ($contact->unsubscribed_by_contact || $this->isBlacklisted($user, (string) $contact->email)) {
            return false;
        }

        $resubscribed = Contact::query()
            ->where('client_id', $user->client_id)
            ->whereKey($contact->getKey())
            ->where('unsubscribed_by_contact', false)
            ->update(['unsubscribed_at' => null]) === 1;

        if (! $resubscribed) {
            $contact->refresh();

            return false;
        }

        $contact->forceFill(['unsubscribed_at' => null])->syncOriginal();

        ContactResubscribed::dispatch($contact);

        return true;
    }

    /**
     * @param  array<int, string|null>  $tagNames
     */
    public function attachTags(User $user, Contact $contact, array $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->filter(fn (?string $name): bool => filled($name))
            ->map(fn (string $name): string => trim($name))
            ->unique()
            ->values()
            ->map(fn (string $name): int => $this->findOrCreateTag->handle($user, $name)->id)
            ->all();

        $contact->syncTags($tagIds, true);
    }

    /**
     * Add and remove tags with one sync, so the tag automations and the
     * sequence tag filters see every change of this update in one event.
     * A name in $remove that has no tag, or a tag that the contact does
     * not have, changes nothing.
     *
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    public function changeTags(User $user, Contact $contact, array $add, array $remove): void
    {
        $addIds = collect($add)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name): int => $this->findOrCreateTag->handle($user, $name)->id);

        $removeIds = $remove === []
            ? collect()
            : Tag::query()->whereIn('name', array_map('trim', $remove))->pluck('id');

        if ($removeIds->isEmpty()) {
            if ($addIds->isNotEmpty()) {
                $contact->syncTags($addIds->values()->all(), true);
            }

            return;
        }

        $currentIds = $contact->tags()->pluck('tags.id');
        $targetIds = $currentIds->merge($addIds)->unique()->diff($removeIds)->values();

        if ($targetIds->sort()->values()->all() !== $currentIds->sort()->values()->all()) {
            $contact->syncTags($targetIds->all());
        }
    }

    /**
     * Add the contact to the list. An add never changes the subscription:
     * an unsubscribe by an admin or by the contact stays. Each channel uses
     * this rule.
     */
    public function attachToList(Contact $contact, MailList $list): void
    {
        $contact->syncLists([$list->id], withoutDetaching: true);

        if ($list->opt_in_enabled) {
            return;
        }

        $this->confirmOptIn($contact, $list);
    }

    /**
     * Record that the contact confirmed the subscription to the list. The
     * first confirmation date stays, because it is the consent record, and
     * `ContactSubscribed` fires only one time. A contact that is not on the
     * list is not added again. The lookup ignores the global scopes, so it
     * also works on the unauthenticated opt-in page.
     */
    public function confirmOptIn(Contact $contact, MailList $list): void
    {
        $membership = $contact->lists()
            ->withoutGlobalScopes()
            ->whereKey($list->id)
            ->first();

        if ($membership === null || $membership->pivot->opted_in_at !== null) {
            return;
        }

        $contact->lists()->updateExistingPivot($list->id, [
            'opted_in_at' => now(),
        ]);

        $contact->subscribeToListTopics([$list->id]);

        ContactSubscribed::dispatch($contact);
    }

    public function detachFromList(Contact $contact, MailList $list): void
    {
        $contact->detachLists([$list->id]);
    }

    public function isBlacklisted(User $user, string $email): bool
    {
        return BlacklistedEmail::withoutGlobalScopes()
            ->where('email', Str::lower($email))
            ->where(function ($query) use ($user): void {
                $query->whereNull('client_id')
                    ->orWhere('client_id', $user->client_id);
            })
            ->exists();
    }

    /**
     * @return array{id: int, email: string, name: ?string, unsubscribed_at: ?string, tags: list<string>, lists: list<int>, sequences: list<array{id: int, name: string, status: string}>}
     */
    public function describe(Contact $contact): array
    {
        $contact->load(['tags', 'lists', 'sequences']);

        return [
            'id' => $contact->id,
            'email' => $contact->email,
            'name' => $contact->name,
            'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
            'tags' => $contact->tags->pluck('name')->values()->all(),
            'lists' => $contact->lists->pluck('id')->values()->all(),
            'sequences' => $contact->sequences
                ->map(fn (Sequence $sequence): array => [
                    'id' => $sequence->id,
                    'name' => $sequence->name,
                    'status' => $sequence->status->value,
                ])
                ->values()
                ->all(),
        ];
    }
}
