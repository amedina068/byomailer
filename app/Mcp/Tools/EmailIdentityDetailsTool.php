<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show a single sender identity in full: verification status, MAIL FROM subdomain, AWS region, custom link domain, tracking flags and the DNS records (DKIM CNAMEs, MAIL FROM MX/TXT and DMARC TXT) the domain owner must publish, each with a verified flag. Use it to guide someone through DNS setup. Find the id with list-email-identities-tool.')]
class EmailIdentityDetailsTool extends Tool
{
    use FormatsEmailIdentity;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
        ]);

        $identity = EmailIdentity::query()
            ->with('dnsRecords')
            ->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        return Response::structured($this->identityDetails($identity));
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
