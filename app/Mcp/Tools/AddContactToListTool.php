<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Models\MailList;
use App\Services\ContactMembership;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add an existing contact to a mailing list without removing their other lists. A list that requires opt-in queues the opt-in email and does not mark the contact opted in. The subscription never changes: an unsubscribed contact stays unsubscribed. Calling it again for a list they already belong to does not send another subscription event. To change many contacts at once, use bulk-update-contacts. Find the contact with find-contact and the list id with view-newsletter-filters-tool.')]
class AddContactToListTool extends Tool
{
    protected string $name = 'add-contact-to-list';

    public function handle(Request $request, ContactMembership $membership): Response|ResponseFactory
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'list_id' => ['required', 'integer'],
        ]);

        $contact = Contact::query()->find($validated['contact_id']);

        if (! $contact) {
            return Response::error("No contact found with id {$validated['contact_id']}.");
        }

        $list = MailList::query()->find($validated['list_id']);

        if (! $list) {
            return Response::error("No list found with id {$validated['list_id']}.");
        }

        $membership->attachToList($contact, $list);

        return Response::structured([
            ...$membership->describe($contact),
            'message' => "Contact #{$contact->id} added to list #{$list->id}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->integer()
                ->description('Id of the contact. Find it with find-contact.')
                ->required(),
            'list_id' => $schema->integer()
                ->description('Id of the mailing list. Find it with view-newsletter-filters-tool.')
                ->required(),
        ];
    }
}
