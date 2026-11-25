<?php

namespace App\Actions;

use App\Enums\SequenceStatus;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Services\SendableContentCheck;
use App\Support\ContentSlot;
use App\Support\Filters\FilterSchema;
use App\Support\HtmlToText;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class UpdateSequenceStep
{
    /**
     * The dashboard form calls the body field "content".
     */
    private const ERROR_KEYS = ['html_content' => 'content'];

    public function __construct(
        private readonly FilterPreparationService $filterPreparationService,
        private readonly SendableContentCheck $sendableContentCheck,
    ) {}

    /**
     * Change the given fields of a step and its message. A field that is not
     * in the array keeps its value. The `html_content` value is the complete
     * HTML, with the template around the content slot when the step has a
     * template.
     *
     * A live step must keep a subject and a body. When the edit removes one,
     * nothing is saved.
     *
     * @param  array{delay_days?: int, delay_type?: string, filters?: array<int, AudienceFilter>, subject?: ?string, preview_text?: ?string, email_identity_id?: int, html_content?: ?string, email_template_id?: ?int}  $changes
     *
     * @throws ValidationException
     */
    public function handle(User $user, SequenceStep $step, array $changes): SequenceStep
    {
        if (array_key_exists('filters', $changes)) {
            $changes['filters'] = $this->filterPreparationService->prepareFilters($changes['filters'], $user);
        }

        return DB::transaction(function () use ($step, $changes): SequenceStep {
            $step = SequenceStep::query()->lockForUpdate()->findOrFail($step->id);
            $message = $step->message;

            if (! $message instanceof Message) {
                throw new ModelNotFoundException("Sequence step #{$step->id} has no message.");
            }

            $stepChanges = array_intersect_key($changes, array_flip(['delay_days', 'delay_type', 'filters']));

            if ($stepChanges !== []) {
                $step->update($stepChanges);
            }

            $messageChanges = $this->messageChanges($step, $changes);

            if ($messageChanges !== []) {
                $message->update($messageChanges);
            }

            if ($step->status === SequenceStatus::Live) {
                $this->refuseUnsendableContent($message);
            }

            return $step;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function messageChanges(SequenceStep $step, array $changes): array
    {
        $messageChanges = array_intersect_key($changes, array_flip(['subject', 'preview_text']));

        if (array_key_exists('email_identity_id', $changes)) {
            $identity = EmailIdentity::query()->findOrFail($changes['email_identity_id']);

            $messageChanges['email_identity_id'] = $identity->id;
            $messageChanges['from_email'] = $identity->email;
            $messageChanges['from_name'] = Sequence::query()->findOrFail($step->sequence_id)->senderNameFor($identity);
        }

        if (array_key_exists('html_content', $changes)) {
            $html = $changes['html_content'] ?? '';
            $templateId = $changes['email_template_id'] ?? null;

            $messageChanges['html_content'] = $html;
            $messageChanges['text_content'] = HtmlToText::convert(ContentSlot::unwrap($html));
            $messageChanges['email_template_id'] = $templateId;
            $messageChanges['template_slot_content'] = $templateId ? ContentSlot::extract($html) : null;
        }

        return $messageChanges;
    }

    /**
     * @throws ValidationException
     */
    private function refuseUnsendableContent(Message $message): void
    {
        $problems = $this->sendableContentCheck->problems($message);

        if ($problems === []) {
            return;
        }

        $messages = [];

        foreach ($problems as $field => $problem) {
            $messages[self::ERROR_KEYS[$field] ?? $field] = $problem;
        }

        throw ValidationException::withMessages($messages);
    }
}
