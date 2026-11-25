<?php

namespace App\Http\Controllers\Api;

use App\Actions\SubscribeContact;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Contacts', 'A contact is one email address of your client, with a name, tags, custom attributes and list memberships. In a create or update request, each body field that is not `email`, `name`, `tags` or `remove_tags` becomes a custom attribute.', weight: 1)]
class ContactSubscribeController extends Controller
{
    /**
     * Subscribe a contact again.
     *
     * Clears an unsubscribe that an admin or the API made. The contact then gets
     * newsletters and sequence emails again. A contact that unsubscribed through
     * an email link gets 422. An address on the suppression list after a
     * complaint, or on the global suppression list, gets 422. To remove a bounce
     * suppression of your client, send `remove_suppression` as true. A subscribed
     * contact does not change.
     */
    #[Response(422, description: 'The contact unsubscribed through an email link, or the address is on a suppression list that cannot be removed (`contact`). The address has a bounce suppression and `remove_suppression` is not true (`remove_suppression`).', type: 'array{message: string, errors: array{contact?: list<string>, remove_suppression?: list<string>}}')]
    public function __invoke(Request $request, int $contactId, SubscribeContact $subscribeContact): JsonResponse
    {
        $request->validate([
            /** Remove the bounce suppression of the address. Without it, a suppressed address gets 422. */
            'remove_suppression' => ['sometimes', 'boolean'],
        ]);

        $contact = $subscribeContact->handle($request->user(), Contact::query()->findOrFail($contactId), $request->boolean('remove_suppression'));

        return ContactResource::make($contact->load(['tags', 'lists', 'topics', 'sequences']))->response();
    }
}
