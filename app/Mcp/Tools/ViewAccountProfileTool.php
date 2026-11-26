<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsAccountProfile;
use App\Models\User;
use App\Services\SunsetPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the account profile: the postal address, the timezone and the sunset rule of the account, and the name of the authenticated user. The postal address, the timezone and the sunset rule apply to the whole team. can_edit tells if this user can change them. If you set include_sunset_preview to true, the result also gives the number of contacts that the next daily sunset run stops mailing. This count scans the contacts, so ask for it only when the user needs it. Change the settings with update-account-profile-tool.')]
class ViewAccountProfileTool extends Tool
{
    use FormatsAccountProfile;

    public function __construct(private readonly SunsetPolicy $sunsetPolicy) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'include_sunset_preview' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $profile = $this->accountProfile($user);

        if ((bool) ($validated['include_sunset_preview'] ?? false)) {
            $profile['sunset_preview'] = $this->sunsetPolicy->preview($user->client);
        }

        return Response::structured($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_sunset_preview' => $schema->boolean()
                ->description('Set to true to add sunset_preview: the number of contacts that the next daily sunset run stops mailing. It is null when the sunset rule is off. Default false.'),
        ];
    }
}
