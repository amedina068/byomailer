<?php

namespace App\Mcp\Tools;

use App\Actions\UpsertContact;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\User;
use App\Services\ContactMembership;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a contact for the authenticated account, or reuse the contact that already has this email. Optional name, tag names, and one list. A new blacklisted email is saved as unsubscribed. An existing email keeps attributes you do not send. Tags are added and never removed — removing a tag is not available through this API. When list_id is set, the contact is added to that list, and a list that requires opt-in queues the opt-in email. The subscription never changes: an unsubscribed contact stays unsubscribed. Find list ids with view-newsletter-filters-tool. Returns the contact id and whether a new row was created.')]
class CreateContactTool extends Tool
{
    protected string $name = 'create-contact';

    public function handle(Request $request, UpsertContact $upsertContact, ContactMembership $membership): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:255'],
            'list_id' => ['sometimes', 'integer'],
        ]);

        $list = null;

        if (array_key_exists('list_id', $validated)) {
            $list = MailList::query()->find($validated['list_id']);

            if (! $list) {
                return Response::error("No list found with id {$validated['list_id']}.");
            }
        }

        $changes = array_intersect_key($validated, array_flip(['name', 'tags']));

        $contact = DB::transaction(function () use ($user, $validated, $changes, $list, $upsertContact, $membership): Contact {
            $contact = $upsertContact->handle($user, $validated['email'], $changes);

            if ($list) {
                $membership->attachToList($contact, $list);
            }

            return $contact;
        }, attempts: 5);

        $created = $contact->wasRecentlyCreated;

        return Response::structured([
            ...$membership->describe($contact),
            'created' => $created,
            'message' => $created
                ? "Contact #{$contact->id} created."
                : "Contact #{$contact->id} updated.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Email address. Reuses the account contact with this exact email when one exists.')
                ->required(),
            'name' => $schema->string()
                ->description('Optional display name. Omitted leaves the current name unchanged.'),
            'tags' => $schema->array()
                ->description('Optional tag names to add. Unknown names are created. Existing tags on the contact are kept. To remove tags, use bulk-update-contacts.')
                ->items($schema->string()),
            'list_id' => $schema->integer()
                ->description('Optional mailing list id. Find ids with view-newsletter-filters-tool. A list that requires opt-in queues the opt-in email.'),
        ];
    }
}
