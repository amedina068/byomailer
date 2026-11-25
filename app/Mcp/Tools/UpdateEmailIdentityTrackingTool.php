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

#[Description('Turn open tracking and click tracking on or off for a sender identity. Updates the identity\'s SES configuration set so future mail from this identity reports (or stops reporting) opens and clicks. Both flags are required. Find the id with list-email-identities-tool.')]
class UpdateEmailIdentityTrackingTool extends Tool
{
    use FormatsEmailIdentity;

    public function __construct(private readonly EmailIdentityProvisioningService $provisioningService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'email_identity_id' => ['required', 'integer'],
            'open_tracking_enabled' => ['required', 'boolean'],
            'click_tracking_enabled' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $identity = EmailIdentity::query()->find($validated['email_identity_id']);

        if (! $identity) {
            return Response::error("No email identity found with id {$validated['email_identity_id']}.");
        }

        try {
            $this->provisioningService->updateTracking(
                $user,
                $identity,
                (bool) $validated['open_tracking_enabled'],
                (bool) $validated['click_tracking_enabled'],
            );
        } catch (EmailIdentityProvisioningException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            ...$this->identitySummary($identity),
            'message' => "Tracking updated for identity #{$identity->id}.",
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
            'open_tracking_enabled' => $schema->boolean()
                ->description('Whether to track opens.')
                ->required(),
            'click_tracking_enabled' => $schema->boolean()
                ->description('Whether to track clicks.')
                ->required(),
        ];
    }
}
