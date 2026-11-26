<?php

namespace App\Mcp\Tools;

use App\Actions\CheckCustomLinkDomain;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Check the DNS and the TLS certificate of the custom link domain of a sender identity, and mark the domain verified when both are live. Call it after the domain owner publishes the CNAME record from update-email-identity-link-domain-tool. A result stays cached for 30 seconds while the domain is pending and for 5 minutes after it is verified. Returns the CNAME record to publish.')]
class CheckEmailIdentityLinkDomainTool extends Tool
{
    use FormatsEmailIdentity;

    public function handle(Request $request, CheckCustomLinkDomain $checkCustomLinkDomain): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
        ]);

        $identity = EmailIdentity::query()->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        if (! $identity->custom_link_domain) {
            return Response::error("Identity #{$identity->id} has no custom link domain. Set one with update-email-identity-link-domain-tool.");
        }

        $result = $checkCustomLinkDomain->handle($identity);

        if (isset($result['error'])) {
            return Response::error("The check of {$identity->custom_link_domain} failed: {$result['error']}");
        }

        $identity->refresh();

        $message = $result['verified']
            ? "{$identity->custom_link_domain} is verified. Links in mail from identity #{$identity->id} use it."
            : "{$identity->custom_link_domain} is not verified yet. Make sure that the CNAME record is published, then check again in a few minutes.";

        return Response::structured([
            ...$this->linkDomainDetails($identity),
            'checked_at' => $result['checked_at'],
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
        ];
    }
}
