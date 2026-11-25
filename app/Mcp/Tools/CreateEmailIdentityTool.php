<?php

namespace App\Mcp\Tools;

use App\Concerns\EmailIdentityValidationRules;
use App\Exceptions\EmailIdentityProvisioningException;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\User;
use App\Services\EmailIdentityProvisioningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a sender identity for the authenticated account. Registers the email\'s domain in the account\'s own AWS SES, sets up a MAIL FROM subdomain (default "mas.<domain>") and a per-identity configuration set, and returns the DNS records the domain owner must publish before the identity can verify. The account must already have AWS credentials configured in the dashboard. Set existing_aws_identity to true to import a domain that is already verified in SES instead of creating it. After the DNS records are published, call refresh-email-identity-status-tool to check verification.')]
class CreateEmailIdentityTool extends Tool
{
    use EmailIdentityValidationRules, FormatsEmailIdentity;

    public function __construct(private readonly EmailIdentityProvisioningService $provisioningService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->emailIdentityRules($user));

        try {
            $identity = $this->provisioningService->provision($user, $validated);
        } catch (EmailIdentityProvisioningException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            ...$this->identityDetails($identity),
            'message' => "Identity #{$identity->id} created. Publish the DNS records, then call refresh-email-identity-status-tool.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Sender email address, for example "hello@example.com". Its domain is registered in SES.')
                ->required(),
            'name' => $schema->string()
                ->description('Optional display name for the sender.'),
            'existing_aws_identity' => $schema->boolean()
                ->description('Set to true when the domain is already registered in the account\'s SES and should be imported instead of created. Defaults to false.'),
            'open_tracking_enabled' => $schema->boolean()
                ->description('Track opens for mail sent from this identity. Defaults to true.'),
            'click_tracking_enabled' => $schema->boolean()
                ->description('Track clicks for mail sent from this identity. Defaults to true.'),
            'mail_from_subdomain' => $schema->string()
                ->description('Optional custom MAIL FROM subdomain, lowercase, for example "bounce.example.com". Defaults to "mas.<domain>".'),
        ];
    }
}
