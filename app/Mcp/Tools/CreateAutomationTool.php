<?php

namespace App\Mcp\Tools;

use App\Actions\CreateAutomation;
use App\Mcp\Concerns\HandlesAutomations;
use App\Models\Automation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create an automation for the authenticated account: when the trigger condition happens to a contact, the actions run on that contact. Automations are always created paused (is_live false). A paused automation can add contacts to a draft sequence. Switch it on with set-automation-status after its sequences are live. Call view-automation-options first to find the condition types, action types and the list, tag and sequence ids. Returns the new automation.')]
class CreateAutomationTool extends Tool
{
    use HandlesAutomations;

    protected string $name = 'create-automation';

    public function handle(Request $request, CreateAutomation $createAutomation): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $data = $this->validateAutomation($user, [
            'type' => Automation::TYPE_SIMPLE,
            ...$request->all(),
        ], isLive: false);

        $automation = $createAutomation->handle($user, $data, isLive: false);

        return Response::structured([
            ...$this->formatAutomation($automation->refresh()),
            'message' => "Paused automation #{$automation->id} created. Switch it on with set-automation-status after the user confirms.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = $this->automationDefinitionSchema($schema);

        $fields['name']->required();
        $fields['condition']->required();
        $fields['actions']->required();

        return $fields;
    }
}
