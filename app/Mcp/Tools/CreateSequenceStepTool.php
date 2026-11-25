<?php

namespace App\Mcp\Tools;

use App\Actions\CreateSequenceStep;
use App\Enums\DelayUnit;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Models\EmailIdentity;
use App\Models\Sequence;
use App\Models\User;
use App\Rules\SafeHtml;
use App\Rules\ValidLiquid;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a draft step to an existing sequence. Steps are always created as drafts. Activate a step with set-sequence-step-status after the user confirms. Set how long after the previous step it sends with delay_type (days or hours) and delay_days. Optionally pass step filters: a contact who does not match them skips this step only. Find the sequence id with list-sequences-tool and a verified sender with view-newsletter-filters-tool. Returns the new step id and status.')]
class CreateSequenceStepTool extends Tool
{
    use BuildsNewsletterFilters;

    public function handle(Request $request, CreateSequenceStep $createSequenceStep): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
            'delay_type' => ['required', Rule::enum(DelayUnit::class)],
            'delay_days' => ['required', 'integer', 'min:0', 'max:'.DelayUnit::maxDelayFor($request->get('delay_type'))],
            'email_identity_id' => [
                'required', 'integer',
                Rule::exists('email_identities', 'id')->where('client_id', $user->client_id),
            ],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255', new ValidLiquid],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255', new SafeHtml, new ValidLiquid],
            'html_content' => ['sometimes', 'nullable', 'string', 'max:524288', new SafeHtml, new ValidLiquid],
            ...$this->filterValidationRules(),
        ]);

        $sequence = Sequence::query()->find($validated['sequence_id']);

        if (! $sequence) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        $identity = EmailIdentity::query()->findOrFail($validated['email_identity_id']);

        $step = $createSequenceStep->handle($user, $sequence, $identity, [
            'delay_days' => $validated['delay_days'],
            'delay_type' => $validated['delay_type'],
            'subject' => $validated['subject'] ?? null,
            'preview_text' => $validated['preview_text'] ?? null,
            'html_content' => $validated['html_content'] ?? null,
            'filters' => $validated['filters'] ?? [],
        ]);

        return Response::structured([
            'id' => $step->id,
            'sequence_id' => $sequence->id,
            'status' => $step->status->value,
            'message' => "Draft step #{$step->id} added to sequence #{$sequence->id}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_id' => $schema->integer()
                ->description('The id of the sequence to add the step to.')
                ->required(),
            'delay_type' => $schema->string()
                ->enum(array_column(DelayUnit::cases(), 'value'))
                ->description('Unit for the delay before this step sends: "days" or "hours".')
                ->required(),
            'delay_days' => $schema->integer()
                ->description('How many days (max 365) or hours (max 8760) after the previous step to send. Use 0 to send immediately on enrollment for the first step.')
                ->required(),
            'email_identity_id' => $schema->integer()
                ->description('Verified sender identity id to send this step from.')
                ->required(),
            'subject' => $schema->string()->description('Email subject line. Supports {{ variable }} template tags.'),
            'preview_text' => $schema->string()->description('Inbox preview / preheader text.'),
            'html_content' => $schema->string()->description('HTML body of the step. Plain-text is derived automatically.'),
            ...$this->filtersSchema($schema, 'Step filters. A contact who does not match them skips only this step and moves on to the next step. The sequence filters still apply first. Use view-newsletter-filters-tool to build the array. Omit to send this step to every contact in the sequence.'),
        ];
    }
}
