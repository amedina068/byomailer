<?php

namespace App\Mcp\Tools;

use App\Exceptions\EmailIdentityProvisioningException;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\EmailIdentityProvisioningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Ask AWS SES for the current verification state of a sender identity and sync it into the account: identity status, DKIM status and MAIL FROM status, plus the verified flag on each DNS record. Call it after the domain owner publishes the DNS records returned by create-email-identity-tool or email-identity-details-tool. Find the id with list-email-identities-tool.')]
class RefreshEmailIdentityStatusTool extends Tool
{
    use FormatsEmailIdentity;

    public function __construct(private readonly EmailIdentityProvisioningService $provisioningService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $identity = EmailIdentity::query()->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        try {
            $this->provisioningService->refreshStatus($user, $identity);
        } catch (EmailIdentityProvisioningException $e) {
            return Response::error($e->getMessage());
        }

        $identity->load('dnsRecords');

        return Response::structured([
            ...$this->identityDetails($identity),
            'message' => "Identity #{$identity->id} is {$identity->identity_status->value}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_identity_id' => $schema->integer()
                ->description('The id of the sender identity to refresh.')
                ->required(),
        ];
    }
}
