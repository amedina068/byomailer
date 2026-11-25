<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContactBulkUpdater
{
    public function __construct(private readonly ContactMembership $membership) {}

    /**
     * Apply one set of changes to one contact. The name, attributes and tags
     * change in one transaction. List changes run after the commit, because
     * the opt-in job is queued without waiting for the commit.
     *
     * @param  array{
     *     name?: string|null,
     *     add_tags?: array<int, string>,
     *     remove_tags?: array<int, string>,
     *     set_attributes?: array<string, string|int|float|bool>,
     *     remove_attributes?: array<int, string>,
     * }  $changes
     * @param  Collection<int, MailList>  $addToLists
     * @param  Collection<int, MailList>  $removeFromLists
     */
    public function apply(User $user, Contact $contact, array $changes, Collection $addToLists, Collection $removeFromLists): Contact
    {
        $contact = DB::transaction(function () use ($user, $contact, $changes): Contact {
            $current = Contact::query()
                ->where('client_id', $user->client_id)
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->applyFields($current, $changes);
            $this->membership->changeTags($user, $current, $changes['add_tags'] ?? [], $changes['remove_tags'] ?? []);

            return $current;
        }, attempts: 5);

        foreach ($addToLists as $list) {
            $this->membership->attachToList($contact, $list);
        }

        foreach ($removeFromLists as $list) {
            $this->membership->detachFromList($contact, $list);
        }

        return $contact;
    }

    /**
     * @param  array{name?: string|null, set_attributes?: array<string, mixed>, remove_attributes?: array<int, string>}  $changes
     */
    private function applyFields(Contact $contact, array $changes): void
    {
        if (array_key_exists('name', $changes)) {
            $contact->name = $changes['name'];
        }

        if (isset($changes['set_attributes']) || isset($changes['remove_attributes'])) {
            $attributes = array_replace($contact->extra_attributes ?? [], $changes['set_attributes'] ?? []);

            foreach ($changes['remove_attributes'] ?? [] as $key) {
                unset($attributes[$key]);
            }

            $contact->extra_attributes = $attributes;
        }

        if ($contact->isDirty()) {
            $contact->save();
        }
    }
}
