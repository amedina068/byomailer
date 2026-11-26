<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateEmailIdentityReplyTo;
use App\Concerns\EmailIdentityValidationRules;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set or clear the Reply-To address of a sender identity. Replies to mail from this identity go to this address. Send reply_to_email as null or an empty string to clear the address and the name. A Reply-To name needs a Reply-To email. Find the id with list-email-identities-tool.')]
class UpdateEmailIdentityReplyToTool extends Tool
{
    use EmailIdentityValidationRules;
    use FormatsEmailIdentity;

    public function handle(Request $request, UpdateEmailIdentityReplyTo $updateReplyTo): Response|ResponseFactory
    {
        foreach (['reply_to_email', 'reply_to_name'] as $field) {
            if (is_string($request->get($field))) {
                $request->merge([$field => trim($request->get($field))]);
            }
        }

        $rules = $this->replyToRules();
        array_unshift($rules['reply_to_email'], 'present');

        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
            ...$rules,
        ], $this->replyToMessages());

        $identity = EmailIdentity::query()->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        $updateReplyTo->handle($identity, $validated['reply_to_email'] ?? null, $validated['reply_to_name'] ?? null);

        $message = $identity->reply_to_email === null
            ? "Reply-To cleared for identity #{$identity->id}."
            : "Reply-To set to {$identity->reply_to_email} for identity #{$identity->id}.";

        return Response::structured([
            ...$this->identitySummary($identity),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_identity_id' => $schema->integer()
                ->description('The id of the sender identity.')
                ->required(),
            'reply_to_email' => $schema->string()
                ->description('The Reply-To email address. Send null or an empty string to clear the Reply-To address.')
                ->nullable()
                ->required(),
            'reply_to_name' => $schema->string()
                ->description('Optional Reply-To display name. It needs reply_to_email.')
                ->nullable(),
        ];
    }
}
