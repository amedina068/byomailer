<?php

namespace App\Actions;

use App\Enums\SequenceStatus;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\ContentSlot;
use App\Support\Filters\FilterSchema;
use App\Support\HtmlToText;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class CreateSequenceStep
{
    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    /**
     * Create a draft step and its message in the sequence. The content goes
     * into the default template of the client when one exists. The user is
     * the author of the message.
     *
     * @param  array{delay_days: int, delay_type: string, subject?: ?string, preview_text?: ?string, html_content?: ?string, filters?: array<int, AudienceFilter>}  $attributes
     */
    public function handle(User $user, Sequence $sequence, EmailIdentity $identity, array $attributes): SequenceStep
    {
        $filters = array_key_exists('filters', $attributes)
            ? $this->filterPreparationService->prepareFilters($attributes['filters'], $user)
            : null;

        return DB::transaction(function () use ($user, $sequence, $identity, $attributes, $filters): SequenceStep {
            $step = SequenceStep::create([
                'sequence_id' => $sequence->id,
                'delay_days' => $attributes['delay_days'],
                'delay_type' => $attributes['delay_type'],
                'status' => SequenceStatus::Draft,
                'filters' => $filters,
            ]);

            $slotContent = ($attributes['html_content'] ?? '') !== '' ? $attributes['html_content'] : '<p></p>';
            $template = EmailTemplate::query()->where('is_default', true)->first();
            $html = $template ? ContentSlot::hydrate($template->html_content, $slotContent) : $slotContent;

            $step->message()->create([
                'user_id' => $user->id,
                'client_id' => $sequence->client_id,
                'email_identity_id' => $identity->id,
                'subject' => $attributes['subject'] ?? null,
                'preview_text' => $attributes['preview_text'] ?? null,
                'from_email' => $identity->email,
                'from_name' => $sequence->senderNameFor($identity),
                'html_content' => $html,
                'text_content' => HtmlToText::convert(ContentSlot::unwrap($html)),
                'email_template_id' => $template?->id,
                'template_slot_content' => $template ? $slotContent : null,
            ]);

            return $step;
        });
    }
}
