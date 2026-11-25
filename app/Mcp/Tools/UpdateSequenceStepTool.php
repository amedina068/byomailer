<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateSequenceStep;
use App\Enums\DelayUnit;
use App\Enums\SequenceStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Models\EmailTemplate;
use App\Models\SequenceStep;
use App\Models\User;
use App\Rules\SafeHtml;
use App\Rules\ValidLiquid;
use App\Support\ContentSlot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a step of a sequence, of any status. A live step sends to real contacts, so the tool refuses the change until you ask the user to confirm and call it again with confirm_live set to true. Draft and paused steps need no confirmation. The status never changes here: use set-sequence-step-status. Only the fields you pass are changed; omitted fields keep their current values. Pair delay_type and delay_days to reschedule the step. Pass filters to replace the step filters (an empty array clears them). Find step ids with sequence-content-tool and a verified sender with view-newsletter-filters-tool. Returns the step id and status.')]
class UpdateSequenceStepTool extends Tool
{
    use BuildsNewsletterFilters;
    use ConfirmsLiveEdits;

    public function handle(Request $request, UpdateSequenceStep $updateSequenceStep): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'sequence_step_id' => ['required', 'integer'],
            'delay_type' => ['sometimes', 'required_with:delay_days', Rule::enum(DelayUnit::class)],
            'delay_days' => ['sometimes', 'required_with:delay_type', 'integer', 'min:0', 'max:'.DelayUnit::maxDelayFor($request->get('delay_type'))],
            'email_identity_id' => [
                'sometimes', 'integer',
                Rule::exists('email_identities', 'id')->where('client_id', $user->client_id),
            ],
            'email_template_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('email_templates', 'id')->where('client_id', $user->client_id),
            ],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255', new ValidLiquid],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255', new SafeHtml, new ValidLiquid],
            'html_content' => ['sometimes', 'nullable', 'string', 'max:524288', new SafeHtml, new ValidLiquid],
            ...$this->filterValidationRules(),
            ...$this->confirmLiveValidationRules(),
        ]);

        $step = SequenceStep::query()->ofCurrentClient()->with('message')->find($validated['sequence_step_id']);

        if ($step === null) {
            return Response::error("No sequence step found with id {$validated['sequence_step_id']}.");
        }

        if ($step->status === SequenceStatus::Live) {
            $refusal = $this->liveEditNeedsConfirmation($validated, "Sequence step #{$step->id}", 'Contacts who reach this step receive the new version.');

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $changes = array_intersect_key($validated, array_flip([
            'filters', 'delay_days', 'delay_type', 'subject', 'preview_text', 'email_identity_id',
        ]));

        if (array_key_exists('html_content', $validated) || array_key_exists('email_template_id', $validated)) {
            $changes += $this->composeContent($step->message()->firstOrNew(), $validated);
        }

        $result = DB::transaction(function () use ($user, $step, $changes, $updateSequenceStep): SequenceStep|Response {
            return $this->statusChangedSinceCheck($step, 'status', "Sequence step #{$step->id}")
                ?? $updateSequenceStep->handle($user, $step, $changes);
        });

        if ($result instanceof Response) {
            return $result;
        }

        $step = $result;

        return Response::structured([
            'id' => $step->id,
            'sequence_id' => $step->sequence_id,
            'status' => $step->status->value,
            'message' => $step->status->label()." step #{$step->id} updated.",
        ]);
    }

    /**
     * Put the content into the template. This tool takes the content of the
     * slot, and keeps the current template and content when one is omitted.
     *
     * @param  array<string, mixed>  $validated
     * @return array{html_content: string, email_template_id: ?int}
     */
    private function composeContent(Model $message, array $validated): array
    {
        $templateId = array_key_exists('email_template_id', $validated)
            ? $validated['email_template_id']
            : $message->email_template_id;

        $slotContent = array_key_exists('html_content', $validated)
            ? ($validated['html_content'] ?? '')
            : ($message->template_slot_content ?? ContentSlot::unwrap($message->html_content ?? ''));

        if ($slotContent === '') {
            $slotContent = '<p></p>';
        }

        $template = $templateId !== null ? EmailTemplate::query()->find($templateId) : null;

        return [
            'html_content' => $template ? ContentSlot::hydrate($template->html_content, $slotContent) : $slotContent,
            'email_template_id' => $template?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sequence_step_id' => $schema->integer()
                ->description('Id of the step to update. Use sequence-content-tool to discover step ids.')
                ->required(),
            'delay_type' => $schema->string()
                ->enum(array_column(DelayUnit::cases(), 'value'))
                ->description('Unit for the delay before this step sends: "days" or "hours". Required together with delay_days.'),
            'delay_days' => $schema->integer()
                ->description('How many days (max 365) or hours (max 8760) after the previous step to send. Required together with delay_type.'),
            'email_identity_id' => $schema->integer()
                ->description('Verified sender identity id to send this step from.'),
            'email_template_id' => $schema->integer()
                ->description('Optional email template id to wrap the content. Pass null to remove the template.'),
            'subject' => $schema->string()->description('Email subject line. Supports Liquid: {{ variable }} placeholders, filters such as {{ name | default: "friend" }}, and tags such as {% if %}.'),
            'preview_text' => $schema->string()->description('Inbox preview / preheader text.'),
            'html_content' => $schema->string()->description('HTML body of the step. Plain-text is derived automatically. Supports Liquid: {{ variable }} placeholders, filters, and the {% if %}, {% unless %}, {% case %} and {% for %} tags. Each output is HTML-escaped. Content that is not valid Liquid is refused with the line of the error.'),
            ...$this->filtersSchema($schema, 'Step filters. A contact who does not match them skips only this step and moves on to the next step. The sequence filters still apply first. Use view-newsletter-filters-tool to build the array. Pass an empty array to clear the filters.'),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
