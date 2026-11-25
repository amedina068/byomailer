<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\HandlesAutomations;
use App\Models\Automation;
use App\Models\Sequence;
use App\Services\AutomationStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Switch an automation on (is_live true) or off (is_live false). This affects real contacts, so the first call only returns a preview: the change, the condition, the actions and the sequences the automation adds contacts to. Nothing is saved. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. A live automation runs on matching events from now on. It does not run on contacts that matched before. The dashboard runs the same check: an automation with an "add_sequence" action can go live only when that sequence is live, because a live automation drops contacts it cannot enroll. Find automation ids with list-automations.')]
class SetAutomationStatusTool extends Tool
{
    use ConfirmsLiveEdits;
    use HandlesAutomations;

    protected string $name = 'set-automation-status';

    public function __construct(private readonly AutomationStatusService $automationStatusService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'automation_id' => ['required', 'integer'],
            'is_live' => ['required', 'boolean'],
            ...$this->confirmLiveValidationRules(),
        ]);

        /** @var Automation|null $automation */
        $automation = Automation::query()->find($validated['automation_id']);

        if ($automation === null) {
            return Response::error("No automation found with id {$validated['automation_id']}.");
        }

        $activate = (bool) $validated['is_live'];

        if ($activate === (bool) $automation->is_live) {
            return Response::structured([
                ...$this->formatAutomation($automation),
                'applied' => false,
                'message' => "Automation #{$automation->id} is already ".($activate ? 'live' : 'paused').'. Nothing changed.',
            ]);
        }

        if ($activate) {
            $problems = $this->automationStatusService->activationProblems($automation);

            if ($problems !== []) {
                return Response::error("Automation #{$automation->id} cannot go live: ".implode(' ', $problems).' Activate the sequence first with set-sequence-status.');
            }
        }

        if (! $this->confirmsLive($validated)) {
            return $this->statusChangePreview([
                ...$this->formatAutomation($automation),
                'change' => $activate ? 'activate' : 'pause',
                'new_is_live' => $activate,
                'target_sequences' => $this->targetSequences($automation),
                'effects' => $activate
                    ? ['The actions run on each contact that matches the condition from now on. Contacts who matched before are not processed.']
                    : ['The actions stop running. Contacts who match the condition while the automation is paused are not processed later.'],
            ]);
        }

        try {
            $this->automationStatusService->setLive($automation, $activate);
        } catch (ValidationException $exception) {
            return Response::error("Automation #{$automation->id} cannot go live: ".implode(' ', $exception->validator->errors()->all()));
        }

        return Response::structured([
            ...$this->formatAutomation($automation->refresh()),
            'applied' => true,
            'message' => "Automation #{$automation->id} ".($activate ? 'activated' : 'paused').'.',
        ]);
    }

    /**
     * @return list<array{id: int, name: string, status: string}>
     */
    private function targetSequences(Automation $automation): array
    {
        $sequenceIds = $this->automationStatusService->sequenceIds($automation);

        if ($sequenceIds === []) {
            return [];
        }

        return Sequence::query()
            ->whereIn('id', $sequenceIds)
            ->get(['id', 'name', 'status'])
            ->map(fn (Sequence $sequence): array => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_id' => $schema->integer()
                ->description('Id of the automation. Use list-automations to discover ids.')
                ->required(),
            'is_live' => $schema->boolean()
                ->description('true switches the automation on. false pauses it.')
                ->required(),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
