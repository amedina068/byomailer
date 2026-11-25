<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateAutomation;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\HandlesAutomations;
use App\Models\Automation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an automation of the authenticated account. A live automation (is_live true) runs on contacts now, so the tool refuses the change until you ask the user to confirm and call it again with confirm_live set to true. Paused automations need no confirmation. The live flag never changes here: use set-automation-status. A paused automation can point an "add_sequence" action to a draft sequence. A live automation needs live sequences. Only the fields you pass are changed: a passed condition replaces the whole condition and a passed actions array replaces all actions. Use view-automation-options for the ids. Returns the updated automation.')]
class UpdateAutomationTool extends Tool
{
    use ConfirmsLiveEdits;
    use HandlesAutomations;

    protected string $name = 'update-automation';

    public function handle(Request $request, UpdateAutomation $updateAutomation): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'automation_id' => ['required', 'integer'],
            ...$this->confirmLiveValidationRules(),
        ]);

        /** @var Automation|null $automation */
        $automation = Automation::query()->find($validated['automation_id']);

        if ($automation === null) {
            return Response::error("No automation found with id {$validated['automation_id']}.");
        }

        if ($automation->is_live) {
            $refusal = $this->liveEditNeedsConfirmation($validated, "Automation #{$automation->id}", 'It runs on contacts now, and the new condition and actions apply to the next matching events.');

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $data = $this->validateAutomation($user, [
            'name' => $request->get('name', $automation->name),
            'type' => $request->get('type', $automation->type),
            'condition' => $request->get('condition', $automation->condition),
            'actions' => $request->get('actions', $automation->actions),
        ], (bool) $automation->is_live);

        $refusal = DB::transaction(function () use ($automation, $data, $updateAutomation): ?Response {
            $changed = $this->statusChangedSinceCheck($automation, 'is_live', "Automation #{$automation->id}");

            if ($changed !== null) {
                return $changed;
            }

            $updateAutomation->handle($automation, $data);

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        return Response::structured([
            ...$this->formatAutomation($automation->refresh()),
            'message' => ($automation->is_live ? 'Live' : 'Paused')." automation #{$automation->id} updated.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_id' => $schema->integer()
                ->description('Id of the automation to update. Use list-automations to discover ids.')
                ->required(),
            ...$this->automationDefinitionSchema($schema),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
