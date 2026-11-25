<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactMail;
use App\Models\Message;
use App\Support\HtmlToText;
use App\Support\ReplacesBlockVariables;
use App\Support\SpreadsheetCell;
use Carbon\CarbonInterface;

/**
 * Builds one CSV row per campaign recipient: delivery status, recipient
 * info, and the personalized plain-text body.
 *
 * The body follows the same steps as `BuildsFromContactMail::resolveMessageContent`.
 * The block expansion runs one time per message, not one time per row, so a
 * large export does not run a block query for each recipient.
 */
class ContactMailExport
{
    use ReplacesBlockVariables;

    /**
     * @var array<int, string>
     */
    private array $expandedBodies = [];

    /**
     * @var array<string, string>
     */
    private array $expandedSubjects = [];

    public function __construct(
        private ?Message $fallbackMessage,
        private Client $client,
    ) {}

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'email',
            'name',
            'status',
            'variant',
            'subject',
            'from_email',
            'from_name',
            'scheduled_at',
            'sent_at',
            'delivered_at',
            'opened_at',
            'clicked_at',
            'bounced_at',
            'complained_at',
            'rejected_at',
            'unsubscribed_at',
            'failure_reason',
            'body',
        ];
    }

    /**
     * @return list<string>
     */
    public function row(ContactMail $contactMail): array
    {
        $message = $contactMail->message ?? $this->fallbackMessage;
        $templateData = $this->templateData($contactMail);

        return array_map(SpreadsheetCell::escapeFormula(...), [
            (string) $contactMail->contact?->email,
            (string) $contactMail->contact?->name,
            (string) $contactMail->status,
            strtoupper((string) $message?->variant?->value),
            $this->subject($contactMail, $message, $templateData),
            (string) $contactMail->from_email,
            (string) $contactMail->from_name,
            $this->timestamp($contactMail->scheduled_at),
            $this->timestamp($contactMail->sent_at),
            $this->timestamp($contactMail->delivered_at),
            $this->timestamp($contactMail->opened_at),
            $this->timestamp($contactMail->clicked_at),
            $this->timestamp($contactMail->bounced_at),
            $this->timestamp($contactMail->complained_at),
            $this->timestamp($contactMail->rejected_at),
            $this->timestamp($contactMail->unsubscribed_at),
            (string) $contactMail->failure_reason,
            $message ? $message->replaceTemplateVariables($this->expandedBody($message), $templateData) : '',
        ]);
    }

    /**
     * Stored template data plus the per-recipient links, as `NewsletterMail`
     * builds it. Nested values are dropped because a placeholder takes a
     * scalar only.
     *
     * @return array<string, string>
     */
    private function templateData(ContactMail $contactMail): array
    {
        $stored = array_filter(
            $contactMail->template_data ?? [],
            fn (mixed $value): bool => is_scalar($value) || $value === null,
        );

        return array_map(fn (mixed $value): string => (string) $value, [
            ...$stored,
            'unsubscribe_url' => $contactMail->unsubscribe_link ?? '',
            'preferences_url' => $contactMail->preferences_link ?? '',
        ]);
    }

    /**
     * @param  array<string, string>  $templateData
     */
    private function subject(ContactMail $contactMail, ?Message $message, array $templateData): string
    {
        $subject = (string) ($contactMail->subject ?? $message?->subject);

        $this->expandedSubjects[$subject] ??= $this->replaceBlockVariablesAsText($subject, $this->client);

        return $message
            ? $message->replaceTemplateVariables($this->expandedSubjects[$subject], $templateData)
            : $this->expandedSubjects[$subject];
    }

    /**
     * The message's plain-text body with blocks expanded and template
     * placeholders left in place.
     */
    private function expandedBody(Message $message): string
    {
        return $this->expandedBodies[$message->id] ??= $message->text_content === null
            ? HtmlToText::convert($this->replaceBlockVariables((string) $message->html_content, $this->client))
            : $this->replaceBlockVariablesAsText($message->text_content, $this->client);
    }

    private function timestamp(?CarbonInterface $value): string
    {
        return $value?->toDateTimeString() ?? '';
    }
}
