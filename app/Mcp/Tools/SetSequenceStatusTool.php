<?php

namespace App\Mcp\Tools;

use App\Enums\SequenceStatus;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FormatsComplianceWarnings;
use App\Models\Sequence;
use App\Models\User;
use App\Services\SequenceStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Activate (status "live") or pause (status "draft", or its alias "paused") a sequence. The result returns the stored status: "live" or "draft". A paused sequence has the status "draft". This affects real contacts, so the first call only returns a preview: the change, the enrolled contacts, the pending mails, the live steps and the live automations that add contacts to the sequence. Nothing is saved. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. A sequence sends only through its live steps, so activate the steps with set-sequence-step-status too. The dashboard runs the same checks: activation fails when the account cannot send email. A live step without a {{ unsubscribe_url }} link or a missing postal address never blocks the activation. The preview and the result list them in warnings. Show the warnings to the user. Find sequence ids with list-sequences-tool.')]
class SetSequenceStatusTool extends Tool
{
    use ConfirmsLiveEdits, FormatsComplianceWarnings;

    protected string $name = 'set-sequence-status';

    public function __construct(private readonly SequenceStatusService $sequenceStatusService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(SequenceStatus::inputValues())],
            ...$this->confirmLiveValidationRules(),
        ]);

        $sequence = Sequence::query()->find($validated['sequence_id']);

        if ($sequence === null) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        $target = SequenceStatus::fromInput($validated['status']);
        $activate = $target === SequenceStatus::Live;
        $isLive = $sequence->status === SequenceStatus::Live;

        if ($activate === $isLive) {
            return Response::structured([
                ...$this->formatSequence($sequence),
                'applied' => false,
                'message' => "Sequence #{$sequence->id} is already ".($isLive ? 'live' : 'paused').'. Nothing changed.',
            ]);
        }

        if ($activate) {
            $problems = $this->sequenceStatusService->sequenceActivationProblems($user);

            if ($problems !== []) {
                return Response::error("Sequence #{$sequence->id} cannot go live: ".implode(' ', $problems));
            }
        }

        $warnings = $activate ? $this->mcpWarnings($this->sequenceStatusService->sequenceWarnings($sequence)) : [];

        if (! $this->confirmsLive($validated)) {
            $impact = $this->sequenceStatusService->sequenceImpact($sequence);

            return $this->statusChangePreview([
                ...$this->formatSequence($sequence),
                'change' => $activate ? 'activate' : 'pause',
                'new_status' => $target->value,
                ...$impact,
                'warnings' => $warnings,
                'effects' => $activate ? $this->activationEffects($impact) : $this->pauseEffects($impact),
            ]);
        }

        try {
            $this->sequenceStatusService->setSequenceStatus($user, $sequence, $target);
        } catch (ValidationException $exception) {
            return Response::error("Sequence #{$sequence->id} cannot go live: ".implode(' ', $exception->validator->errors()->all()));
        }

        return Response::structured([
            ...$this->formatSequence($sequence->refresh()),
            'warnings' => $warnings,
            'applied' => true,
            'message' => "Sequence #{$sequence->id} ".($activate ? 'activated' : 'paused').'.',
        ]);
    }

    /**
     * @return array{id: int, name: string, status: string}
     */
    private function formatSequence(Sequence $sequence): array
    {
        return [
            'id' => $sequence->id,
            'name' => $sequence->name,
            'status' => $sequence->status->value,
        ];
    }

    /**
     * @param  array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int, live_steps: int, total_steps: int, live_automation_ids: list<int>}  $impact
     * @return list<string>
     */
    private function activationEffects(array $impact): array
    {
        $effects = [
            "{$impact['pending_mails']} pending mails send when they are due. {$impact['pending_mails_due_now']} of them are due now and send within minutes.",
            'Contacts that automations, imports or the API add from now on start the sequence.',
        ];

        if ($impact['live_steps'] === 0) {
            $effects[] = "No step is live ({$impact['total_steps']} steps in total), so nothing sends until a step goes live.";
        }

        return $effects;
    }

    /**
     * @param  array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int, live_steps: int, total_steps: int, live_automation_ids: list<int>}  $impact
     * @return list<string>
     */
    private function pauseEffects(array $impact): array
    {
        $effects = [
            "{$impact['enrolled_contacts']} enrolled contacts stop receiving the sequence.",
            "{$impact['pending_mails']} pending mails do not send while the sequence is paused. They stay pending, and when you activate the sequence again, the mails that are due send within minutes.",
            'New contacts are not enrolled while the sequence is paused, and they are not enrolled later.',
        ];

        if ($impact['live_automation_ids'] !== []) {
            $effects[] = 'Live automations '.implode(', ', $impact['live_automation_ids']).' add contacts to this sequence. Those contacts are skipped while it is paused.';
        }

        return $effects;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('Id of the sequence. Use list-sequences-tool to discover sequence ids.')
                ->required(),
            'status' => $schema->string()
                ->enum(SequenceStatus::inputValues())
                ->description('"live" activates the sequence. "draft" stops it. "paused" is an alias of "draft".')
                ->required(),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
