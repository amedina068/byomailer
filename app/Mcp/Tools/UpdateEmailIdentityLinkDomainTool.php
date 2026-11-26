<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateCustomLinkDomain;
use App\Concerns\EmailIdentityValidationRules;
use App\Exceptions\CloudflareException;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\CloudflareClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set or clear the custom link domain of a sender identity. The unsubscribe, preference and opt-in links in mail from this identity then use this domain, for example links.example.com. Send custom_link_domain as null or an empty string to clear it. After you set a domain, the domain owner must publish the CNAME record in dns_records. Then call check-email-identity-link-domain-tool. Links use the domain only after it is verified. Find the id with list-email-identities-tool.')]
class UpdateEmailIdentityLinkDomainTool extends Tool
{
    use EmailIdentityValidationRules;
    use FormatsEmailIdentity;

    public function __construct(private readonly CloudflareClient $cloudflare) {}

    public function handle(Request $request, UpdateCustomLinkDomain $updateCustomLinkDomain): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->get('custom_link_domain') !== null) {
            $request->merge(['custom_link_domain' => $this->normalizeLinkDomain($request->get('custom_link_domain'))]);
        }

        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
            'custom_link_domain' => ['present', 'nullable', ...$this->customLinkDomainRules($user->client_id)],
        ], $this->customLinkDomainMessages());

        $identity = EmailIdentity::query()->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        if (! $this->cloudflare->isConfigured()) {
            return Response::error('Custom link domains are not available on this BYOMailer server. No change was saved.');
        }

        $domain = blank($validated['custom_link_domain']) ? null : $validated['custom_link_domain'];

        try {
            $updateCustomLinkDomain->handle($identity, $domain);
        } catch (CloudflareException $e) {
            return Response::error("Cloudflare did not register the link domain: {$e->getMessage()} No change was saved.");
        }

        $message = $domain === null
            ? "Custom link domain removed from identity #{$identity->id}. Links use the default domain."
            : "Custom link domain {$domain} saved for identity #{$identity->id}. Publish the CNAME record, then call check-email-identity-link-domain-tool.";

        return Response::structured([
            ...$this->linkDomainDetails($identity),
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
            'custom_link_domain' => $schema->string()
                ->description('A hostname that you control, for example links.example.com. Send null or an empty string to clear the custom link domain.')
                ->nullable()
                ->required(),
        ];
    }
}
