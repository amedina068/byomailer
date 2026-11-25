<?php

namespace App\Http\Controllers\Api;

use App\Actions\UnsubscribeContact;
use App\Actions\UpsertContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\MailList;
use App\Services\ContactMembership;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

#[Group('Contacts', 'A contact is one email address of your client, with a name, tags, custom attributes and list memberships. In a create or update request, each body field that is not `email`, `name`, `tags` or `remove_tags` becomes a custom attribute.', weight: 1)]
class ContactController extends Controller
{
    /**
     * List contacts.
     *
     * Returns the contacts of your client, newest first. Unsubscribed contacts are
     * left out unless `include_unsubscribed` is true. The filters `lists`, `tags`
     * and `topics` each match a contact that has one of the given IDs.
     */
    #[QueryParameter('lists', description: 'Return only contacts on one of these list IDs.', type: 'array<int>')]
    #[QueryParameter('tags', description: 'Return only contacts with one of these tag IDs.', type: 'array<int>')]
    #[QueryParameter('topics', description: 'Return only contacts subscribed to one of these topic IDs.', type: 'array<int>')]
    #[QueryParameter('search', description: 'Return only contacts whose name or email contains this text.', type: 'string')]
    #[QueryParameter('include_unsubscribed', description: 'Also return unsubscribed contacts.', type: 'bool', default: false)]
    #[QueryParameter('per_page', description: 'The number of items on one page, from 1 to 100.', type: 'int', default: 25)]
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $lists = (array) $request->input('lists', []);
        $tags = (array) $request->input('tags', []);
        $topics = (array) $request->input('topics', []);
        $search = (string) $request->input('search', '');

        $contacts = Contact::query()
            ->with(['tags', 'lists', 'topics', 'sequences'])
            ->when(! $request->boolean('include_unsubscribed'),
                fn ($q) => $q->whereNull('unsubscribed_at'))
            ->when($lists, fn ($q) => $q->whereHas('lists',
                fn ($sq) => $sq->whereIn('lists.id', $lists)))
            ->when($tags, fn ($q) => $q->whereHas('tags',
                fn ($sq) => $sq->whereIn('tags.id', $tags)))
            ->when($topics, fn ($q) => $q->whereHas('topics',
                fn ($sq) => $sq->whereIn('topics.id', $topics)
                    ->whereNull('contact_topic.unsubscribed_at')))
            ->when($search !== '', fn ($q) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return ContactResource::collection($contacts);
    }

    /**
     * Get a contact.
     *
     * Returns one contact with its tags, lists, topics and sequences.
     */
    public function show(Request $request, int $contactId): JsonResponse
    {
        $contact = Contact::query()
            ->with(['tags', 'lists', 'topics', 'sequences'])
            ->findOrFail($contactId);

        return ContactResource::make($contact)->response();
    }

    /**
     * Add a contact to a list.
     *
     * Adds the contact with this email to the list. If the email is new, the
     * contact is created and the response is 201. If the contact exists, it is
     * updated and the response is 200. When the list has opt-in and the contact is
     * new on the list, the contact gets a confirmation email. A newsletter or a
     * sequence reaches the contact through this list only after it confirms. Tags
     * in `tags` are created when they do not exist. A new contact with a
     * blacklisted email gets 422.
     * The request never changes the subscription. An unsubscribed contact stays
     * unsubscribed. To subscribe it again, use the subscribe endpoint.
     */
    #[Response(201, description: 'The contact is new.', type: 'ContactResource')]
    #[Response(200, description: 'The contact existed, and the request updated it.', type: 'ContactResource')]
    public function store(ContactRequest $request, int $listId, UpsertContact $upsertContact, ContactMembership $membership): JsonResponse
    {
        $list = MailList::query()->findOrFail($listId);

        $contact = DB::transaction(function () use ($request, $upsertContact, $membership, $list): Contact {
            $contact = $upsertContact->handle($request->user(), $request->validated('email'), $this->changesFromRequest($request));

            $membership->attachToList($contact, $list);

            return $contact;
        }, 5);

        $status = $contact->wasRecentlyCreated ? 201 : 200;

        return ContactResource::make($contact->load(['tags', 'lists', 'topics', 'sequences']))
            ->response()
            ->setStatusCode($status);
    }

    /**
     * Update a contact.
     *
     * Changes the email, the name, the tags or the custom attributes of a contact.
     * Only the fields in the request change. `tags` adds tags and `remove_tags`
     * removes tags. Each other body field sets a custom attribute. An update never
     * changes the subscription. To subscribe a contact again, use the subscribe
     * endpoint or add the contact to a list.
     */
    public function update(ContactRequest $request, int $contactId, ContactMembership $membership): JsonResponse
    {
        $contact = Contact::query()->findOrFail($contactId);

        $contact = $membership->persist($request->user(), $contact, $this->changesFromRequest($request));

        return ContactResource::make($contact->load(['tags', 'lists', 'topics', 'sequences']))->response();
    }

    /**
     * Unsubscribe a contact.
     *
     * Marks the contact as unsubscribed. The contact then gets no newsletters and
     * no sequence emails. A second call keeps the first unsubscribe date.
     */
    public function unsubscribe(Request $request, int $contactId, UnsubscribeContact $unsubscribeContact): JsonResponse
    {
        $contact = $unsubscribeContact->handle($request->user(), Contact::query()->findOrFail($contactId));

        return ContactResource::make($contact->load(['tags', 'lists', 'topics', 'sequences']))->response();
    }

    /**
     * Remove a contact from a list.
     *
     * Removes the contact from one list. The contact stays in your client and on
     * its other lists. The removal runs the live list automations of your client.
     */
    public function removeFromList(Request $request, $listId, int $contactId, ContactMembership $membership): JsonResponse
    {
        $list = MailList::query()->findOrFail($listId);

        $contact = Contact::query()->findOrFail($contactId);

        $membership->detachFromList($contact, $list);

        return ContactResource::make($contact->load(['tags', 'lists', 'topics', 'sequences']))->response();
    }

    /**
     * @return array{
     *     email?: string,
     *     name?: string|null,
     *     tags?: array<int, string|null>,
     *     remove_tags?: array<int, string>,
     *     extra_attributes?: array<string, mixed>,
     * }
     */
    private function changesFromRequest(ContactRequest $request): array
    {
        $changes = [];

        if ($request->has('email')) {
            $changes['email'] = $request->validated('email');
        }

        if ($request->has('name')) {
            $changes['name'] = $request->validated('name');
        }

        if ($request->has('tags')) {
            $changes['tags'] = $request->validated('tags', []);
        }

        if ($request->has('remove_tags')) {
            $changes['remove_tags'] = $request->validated('remove_tags', []);
        }

        $extra = $request->except(['name', 'email', 'tags', 'remove_tags']);

        if ($extra !== []) {
            $changes['extra_attributes'] = $extra;
        }

        return $changes;
    }
}
