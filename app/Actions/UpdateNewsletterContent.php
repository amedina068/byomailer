<?php

namespace App\Actions;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Services\SendableContentCheck;
use App\Support\ContentSlot;
use App\Support\ContentVariables;
use App\Support\HtmlToText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateNewsletterContent
{
    /**
     * The keys that `handle()` reads from `$changes`.
     */
    public const FIELDS = [
        'title', 'subject', 'preview_text', 'html_content', 'email_template_id', 'email_identity_id',
        'ab_test_enabled', 'subject_b', 'preview_text_b', 'html_content_b', 'filters', 'segment_id',
        'content_variables', 'project_ids',
    ];

    public const SCHEDULED_NEEDS_CONFIRMATION = 'The newsletter is scheduled now. Confirm the change to a scheduled newsletter, then try again.';

    public function __construct(
        private readonly FilterPreparationService $filterPreparationService,
        private readonly SendableContentCheck $sendableContentCheck,
        private readonly UpdateNewsletterLabels $updateNewsletterLabels,
    ) {}

    /**
     * Write the content of a draft or scheduled newsletter: the title, the
     * message and its A/B variant, the sender, the template, the audience, the
     * content variable defaults and the projects. Only the keys in `$changes`
     * change. The title and the projects go through UpdateNewsletterLabels. A content variable list keeps every key sent and adds an empty
     * default for each variable that the content uses.
     *
     * The write runs in one transaction, only while the newsletter is draft or
     * scheduled in the database. A scheduled newsletter must stay sendable,
     * so an edit that leaves a blank subject or body fails.
     *
     * @param  array{
     *     title?: ?string,
     *     subject?: ?string,
     *     preview_text?: ?string,
     *     html_content?: ?string,
     *     email_template_id?: ?int,
     *     email_identity_id?: ?int,
     *     ab_test_enabled?: bool,
     *     subject_b?: ?string,
     *     preview_text_b?: ?string,
     *     html_content_b?: ?string,
     *     filters?: ?array<int, array<string, mixed>>,
     *     segment_id?: ?int,
     *     content_variables?: ?array<string, string|null>,
     *     project_ids?: ?array<int, int|string>,
     * }  $changes
     * @param  bool  $scheduledAllowed  false refuses the edit when the newsletter is scheduled
     *
     * @throws ValidationException when the newsletter is sending or sent, when a
     *                             scheduled newsletter is not allowed or would not
     *                             be sendable, or when the HTML has no plain text form
     */
    public function handle(User $user, Newsletter $newsletter, array $changes, bool $scheduledAllowed = true): Newsletter
    {
        DB::transaction(function () use ($user, $newsletter, $changes, $scheduledAllowed): void {
            $status = $this->lockMutableStatus($newsletter);

            if ($status === NewsletterStatus::Scheduled && ! $scheduledAllowed) {
                throw ValidationException::withMessages(['status' => self::SCHEDULED_NEEDS_CONFIRMATION]);
            }

            $this->saveNewsletter($user, $newsletter, $changes);
            $this->saveMessages($user, $newsletter, $changes);
            $this->saveContentVariables($newsletter, $changes);
            $this->updateNewsletterLabels->handle($newsletter, Arr::only($changes, UpdateNewsletterLabels::FIELDS));

            if ($status === NewsletterStatus::Scheduled) {
                $this->assertSendable($newsletter);
            }
        });

        return $newsletter;
    }

    /**
     * Read the status with a row lock while the newsletter is mutable. The
     * scheduler waits for the commit before it can queue the newsletter.
     *
     * @throws ValidationException
     */
    private function lockMutableStatus(Newsletter $newsletter): NewsletterStatus
    {
        $status = Newsletter::query()
            ->whereKey($newsletter->getKey())
            ->mutable()
            ->lockForUpdate()
            ->value('status');

        if ($status === null) {
            $current = Newsletter::query()->whereKey($newsletter->getKey())->value('status') ?? $newsletter->status;

            throw ValidationException::withMessages([
                'status' => "The newsletter is {$current->value}. Only a draft or scheduled newsletter can change.",
            ]);
        }

        $newsletter->status = $status;
        $newsletter->syncOriginalAttribute('status');
        $newsletter->load(['message', 'messageB']);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function saveNewsletter(User $user, Newsletter $newsletter, array $changes): void
    {
        $attributes = $this->audience($user, $changes);

        if (array_key_exists('ab_test_enabled', $changes)) {
            $attributes['ab_test_enabled'] = (bool) $changes['ab_test_enabled'];
        }

        if ($attributes !== []) {
            $newsletter->fill($attributes)->save();
        }
    }

    /**
     * The variables need the saved content, so they come after the messages.
     *
     * @param  array<string, mixed>  $changes
     */
    private function saveContentVariables(Newsletter $newsletter, array $changes): void
    {
        if (array_key_exists('content_variables', $changes)) {
            $variables = ContentVariables::withDetected($newsletter->detectedTemplateVariables(), $changes['content_variables']);
            ksort($variables);

            $newsletter->forceFill(['content_variables' => $variables])->save();
        }

    }

    /**
     * The audience is a saved segment or inline filters, never both. A
     * segment wins and clears the filters. Filters clear the segment.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function audience(User $user, array $changes): array
    {
        if (($changes['segment_id'] ?? null) !== null) {
            return ['segment_id' => $changes['segment_id'], 'filters' => []];
        }

        if (array_key_exists('filters', $changes)) {
            return [
                'segment_id' => null,
                'filters' => $this->filterPreparationService->prepareFilters($changes['filters'] ?? [], $user),
            ];
        }

        return array_key_exists('segment_id', $changes) ? ['segment_id' => null] : [];
    }

    /**
     * Message A holds the sender and the template. Variant B copies both.
     *
     * @param  array<string, mixed>  $changes
     */
    private function saveMessages(User $user, Newsletter $newsletter, array $changes): void
    {
        $abTestEnabled = (bool) $newsletter->ab_test_enabled;

        $messageA = $newsletter->message ?? $this->newMessage($user, $newsletter);
        $messageA->variant = $abTestEnabled ? AbVariant::A : null;
        $this->fillSender($user, $messageA, $changes);
        $this->fillContent($messageA, $changes, AbVariant::A);
        $messageA->save();
        $newsletter->setRelation('message', $messageA);

        if (! $abTestEnabled) {
            $newsletter->messageB()->delete();
            $newsletter->setRelation('messageB', null);

            return;
        }

        $messageB = $newsletter->messageB ?? $this->newMessage($user, $newsletter);
        $messageB->forceFill([
            'variant' => AbVariant::B,
            'email_template_id' => $messageA->email_template_id,
            'email_identity_id' => $messageA->email_identity_id,
            'from_email' => $messageA->from_email,
            'from_name' => $messageA->from_name,
        ]);
        $this->fillContent($messageB, $changes, AbVariant::B);
        $messageB->save();
        $newsletter->setRelation('messageB', $messageB);
    }

    private function newMessage(User $user, Newsletter $newsletter): Message
    {
        return new Message([
            'messageable_type' => $newsletter->getMorphClass(),
            'messageable_id' => $newsletter->getKey(),
            'user_id' => $user->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function fillSender(User $user, Message $message, array $changes): void
    {
        if (! array_key_exists('email_identity_id', $changes)) {
            return;
        }

        if ($changes['email_identity_id'] === null) {
            $message->email_identity_id = null;

            return;
        }

        $identity = EmailIdentity::query()->findOrFail($changes['email_identity_id']);

        $message->forceFill([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name ?: $user->name,
        ]);
    }

    /**
     * Variant B reads the fields with the `_b` suffix. Message A of a
     * newsletter without an A/B test reads the fields of variant A.
     *
     * @param  array<string, mixed>  $changes
     */
    private function fillContent(Message $message, array $changes, AbVariant $variant): void
    {
        $suffix = $variant->fieldSuffix();

        if (array_key_exists('subject'.$suffix, $changes)) {
            $message->subject = $changes['subject'.$suffix] ?? '';
        }

        if (array_key_exists('preview_text'.$suffix, $changes)) {
            $message->preview_text = $changes['preview_text'.$suffix];
        }

        if ($variant === AbVariant::A && array_key_exists('email_template_id', $changes)) {
            $message->email_template_id = $changes['email_template_id'];
        }

        if (array_key_exists('html_content'.$suffix, $changes)) {
            $html = (string) ($changes['html_content'.$suffix] ?? '');
            $message->html_content = $html;
            $message->text_content = $this->plainText($html, 'html_content'.$suffix);
        }

        if ($message->isDirty(['html_content', 'email_template_id']) || ! $message->exists) {
            $message->template_slot_content = $this->templateSlotContent($message->email_template_id, (string) $message->html_content);
        }
    }

    /**
     * The one rule for the slot content of a template. With no template, there
     * is no slot. With a template, the slot is the content inside the
     * `data-slot="content"` wrapper, or the whole HTML when it has no wrapper.
     */
    private function templateSlotContent(?int $templateId, string $html): ?string
    {
        if ($templateId === null) {
            return null;
        }

        return ContentSlot::hasWrapper($html) ? ContentSlot::extract($html) : $html;
    }

    /**
     * @throws ValidationException when the HTML has no plain text form
     */
    private function plainText(string $html, string $field): string
    {
        try {
            return HtmlToText::convert($html);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                $field => ["The {$field} could not be converted to plain text: ".$e->getMessage()],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertSendable(Newsletter $newsletter): void
    {
        $problems = $this->sendableContentCheck->problems($newsletter->message);

        if ($newsletter->ab_test_enabled) {
            $problems += $this->sendableContentCheck->problems($newsletter->messageB, AbVariant::B);
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
}
