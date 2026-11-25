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

#[Description('Remove a contact from one mailing list. Their other lists stay. The contact row is not deleted. The removal runs the live list automations of the account. Find the contact with find-contact and the list id with view-newsletter-filters-tool.')]
class RemoveContactFromListTool extends Tool
{
    protected string $name = 'remove-contact-from-list';

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

        $membership->detachFromList($contact, $list);

        return Response::structured([
            ...$membership->describe($contact),
            'message' => "Contact #{$contact->id} removed from list #{$list->id}.",
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
                ->description('Id of the mailing list to remove them from.')
                ->required(),
        ];
    }
}
