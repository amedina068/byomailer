<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Models\User;
use App\Services\ContactMembership;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add tags to an existing contact by name. Tags already on the contact stay. A name that does not exist yet is created for this account. create-tag-tool remains the strict way to create a tag and still errors on a duplicate name. To remove tags, or to change many contacts at once, use bulk-update-contacts. Find the contact with find-contact.')]
class AddTagsToContactTool extends Tool
{
    protected string $name = 'add-tags-to-contact';

    public function handle(Request $request, ContactMembership $membership): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:255'],
        ]);

        $contact = Contact::query()->find($validated['contact_id']);

        if (! $contact) {
            return Response::error("No contact found with id {$validated['contact_id']}.");
        }

        $membership->attachTags($user, $contact, $validated['tags']);

        return Response::structured([
            ...$membership->describe($contact),
            'message' => "Tags added to contact #{$contact->id}.",
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
            'tags' => $schema->array()
                ->description('Tag names to add. Unknown names are created. Existing tags on the contact are kept. To remove tags, use bulk-update-contacts.')
                ->items($schema->string())
                ->required(),
        ];
    }
}
