<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Services\ContactMembership;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Look up one contact in the authenticated account by exact email. Returns the contact even when they are unsubscribed, or contact null when this account has no such email. The result includes the tags, lists, and sequences of the contact. To list or filter many contacts, use list-contacts.')]
class FindContactTool extends Tool
{
    protected string $name = 'find-contact';

    public function handle(Request $request, ContactMembership $membership): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $contact = Contact::query()
            ->select(['id', 'email', 'name', 'unsubscribed_at'])
            ->where('email', $validated['email'])
            ->first();

        return Response::structured([
            'contact' => $contact ? $membership->describe($contact) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Exact email address to look up in this account.')
                ->required(),
        ];
    }
}
