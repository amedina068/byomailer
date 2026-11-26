<?php

namespace App\Mcp\Tools;

use App\Enums\SequenceStatus;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FormatsComplianceWarnings;
use App\Models\Sequence;
use App\Models\SequenceStep;
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

#[Description('Activate (status "live") or pause (status "draft", or its alias "paused") one step of a sequence. The result returns the stored statuses: "live" or "draft". A paused step has the status "draft". This affects real contacts, so the first call only returns a preview: the change, the contacts enrolled in the sequence and the pending mails of this step. Nothing is saved. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. The dashboard runs the same checks: activation fails when the account cannot send email or the step has no subject or body. A missing {{ unsubscribe_url }} link or a missing postal address never blocks the activation. The preview and the result list them in warnings. Show the warnings to the user. A live step sends only while its sequence is live. Find step ids with sequence-content-tool.')]
class SetSequenceStepStatusTool extends Tool
{
    use ConfirmsLiveEdits, FormatsComplianceWarnings;

    protected string $name = 'set-sequence-step-status';

    public function __construct(private readonly SequenceStatusService $sequenceStatusService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'sequence_step_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(SequenceStatus::inputValues())],
            ...$this->confirmLiveValidationRules(),
        ]);

        $step = SequenceStep::query()->with('message')->find($validated['sequence_step_id']);
        $sequence = $step === null ? null : Sequence::query()->find($step->sequence_id);

        if ($step === null || $sequence === null) {
            return Response::error("No sequence step found with id {$validated['sequence_step_id']}.");
        }

        $target = SequenceStatus::fromInput($validated['status']);
        $activate = $target === SequenceStatus::Live;
        $isLive = $step->status === SequenceStatus::Live;

        if ($activate === $isLive) {
            return Response::structured([
                ...$this->formatStep($step, $sequence),
                'applied' => false,
                'message' => "Sequence step #{$step->id} is already ".($isLive ? 'live' : 'paused').'. Nothing changed.',
            ]);
        }

        if ($activate) {
            $problems = $this->sequenceStatusService->stepActivationProblems($user, $step);

            if ($problems !== []) {
                return Response::error("Sequence step #{$step->id} cannot go live: ".implode(' ', $problems));
            }
        }

        $warnings = $activate ? $this->mcpWarnings($this->sequenceStatusService->stepWarnings($step)) : [];

        if (! $this->confirmsLive($validated)) {
            $impact = $this->sequenceStatusService->stepImpact($sequence, $step);

            return $this->statusChangePreview([
                ...$this->formatStep($step, $sequence),
                'change' => $activate ? 'activate' : 'pause',
                'new_status' => $target->value,
                ...$impact,
                'warnings' => $warnings,
                'effects' => $activate ? $this->activationEffects($sequence, $impact) : $this->pauseEffects($impact),
            ]);
        }

        try {
            $this->sequenceStatusService->setStepStatus($user, $step, $target);
        } catch (ValidationException $exception) {
            return Response::error("Sequence step #{$step->id} cannot go live: ".implode(' ', $exception->validator->errors()->all()));
        }

        return Response::structured([
            ...$this->formatStep($step->refresh(), $sequence),
            'warnings' => $warnings,
            'applied' => true,
            'message' => "Sequence step #{$step->id} ".($activate ? 'activated' : 'paused').'.',
        ]);
    }

    /**
     * @return array{id: int, sequence_id: int, sequence_status: string, subject: ?string, status: string}
     */
    private function formatStep(SequenceStep $step, Sequence $sequence): array
    {
        return [
            'id' => $step->id,
            'sequence_id' => $sequence->id,
            'sequence_status' => $sequence->status->value,
            'subject' => $step->message?->subject,
            'status' => $step->status->value,
        ];
    }

    /**
     * @param  array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int}  $impact
     * @return list<string>
     */
    private function activationEffects(Sequence $sequence, array $impact): array
    {
        $effects = [
            "{$impact['enrolled_contacts']} contacts are enrolled in the sequence. Contacts who reach this step from now on receive it. Contacts who already passed it do not.",
            "{$impact['pending_mails_due_now']} pending mails of this step are due now and send within minutes.",
        ];

        if ($sequence->status !== SequenceStatus::Live) {
            $effects[] = 'The sequence is not live, so this step sends nothing until the sequence goes live.';
        }

        return $effects;
    }

    /**
     * @param  array{enrolled_contacts: int, pending_mails: int, pending_mails_due_now: int}  $impact
     * @return list<string>
     */
    private function pauseEffects(array $impact): array
    {
        return [
            "{$impact['pending_mails']} pending mails of this step do not send while the step is paused. They stay pending, and when you activate the step again, the mails that are due send within minutes.",
            'Contacts who reach this point later skip this step and get the next live step.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_step_id' => $schema->integer()
                ->description('Id of the sequence step. Use sequence-content-tool to discover step ids.')
                ->required(),
            'status' => $schema->string()
                ->enum(SequenceStatus::inputValues())
                ->description('"live" activates the step. "draft" stops it. "paused" is an alias of "draft".')
                ->required(),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
