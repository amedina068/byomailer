<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateAccountSettings;
use App\Concerns\ProfileValidationRules;
use App\Mcp\Concerns\FormatsAccountProfile;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change the account settings that apply to the whole team: the postal address, the timezone and the sunset rule. Send only the fields to change. The tool uses the same validation as the Profile page. Only an Admin or an Editor with a write token can use this tool. A Viewer does not see it. The tool cannot change the name, the email or the password of a user. Laws such as CAN-SPAM ask for a postal address in each marketing email. Put {{ postal_address }} in the footer of your templates. The sunset rule stops mail to contacts who did not click in that number of days, at the next daily run. Before you change the sunset rule, show the change to the user and get a confirmation. Read the current values with view-account-profile-tool.')]
class UpdateAccountProfileTool extends Tool
{
    use FormatsAccountProfile, ProfileValidationRules;

    /**
     * The MCP text of the postal address warning. It names this tool, because
     * an AI client cannot open the Settings page.
     */
    public const POSTAL_ADDRESS_WARNING = 'Your account has no postal address. Laws such as CAN-SPAM ask for a postal address in each marketing email. Set it with update-account-profile-tool, or in Settings, Profile. Put {{ postal_address }} in the footer.';

    public function handle(Request $request, UpdateAccountSettings $updateAccountSettings): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $rules = array_map(
            fn (array $fieldRules): array => ['sometimes', ...$fieldRules],
            $this->accountSettingsRules(),
        );

        $validated = $request->validate($rules);

        if ($validated === []) {
            return Response::error('Send at least one field to change: postal_address, timezone or sunset_inactive_days.');
        }

        $updateAccountSettings->handle($user->client, $validated);

        $user->unsetRelation('client');

        return Response::structured([
            ...$this->accountProfile($user),
            'updated' => array_keys($validated),
            'message' => 'Account settings updated: '.implode(', ', array_keys($validated)).'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'postal_address' => $schema->string()
                ->description('The postal address of the sender, up to 500 characters. Templates show it with {{ postal_address }}. Send null or an empty string to remove it.')
                ->nullable(),
            'timezone' => $schema->string()
                ->description('An IANA timezone, for example "America/Chicago". BYOMailer uses it to schedule newsletters and automations.'),
            'sunset_inactive_days' => $schema->integer()
                ->description('Stop mail to contacts who did not click in this number of days, from 30 to 730. Send null to turn the sunset rule off.')
                ->nullable(),
        ];
    }
}
