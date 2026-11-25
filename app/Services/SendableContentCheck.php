<?php

namespace App\Services;

use App\Enums\AbVariant;
use App\Models\Client;
use App\Models\Message;
use App\Support\HtmlToText;

/**
 * Finds content that must not go out: a blank subject or a blank body.
 * Mailbox providers treat such mail as spam, so these problems block a send.
 *
 * It also finds two compliance gaps: a body without an unsubscribe link and an
 * account without a postal address. The sender is responsible for both, so
 * these are warnings only. They never block a send.
 */
class SendableContentCheck
{
    public const SUBJECT_MESSAGE = 'Add a subject before you send.';

    public const CONTENT_MESSAGE = 'Add content before you send.';

    public const SUBJECT_B_MESSAGE = 'Add a subject to variant B before you send.';

    public const CONTENT_B_MESSAGE = 'Add content to variant B before you send.';

    public const UNSUBSCRIBE_WARNING = 'The content has no unsubscribe link ({{ unsubscribe_url }}). '.self::UNSUBSCRIBE_REASON;

    public const UNSUBSCRIBE_B_WARNING = 'Variant B has no unsubscribe link ({{ unsubscribe_url }}). '.self::UNSUBSCRIBE_REASON;

    public const POSTAL_ADDRESS_WARNING = 'Your account has no postal address. Laws such as CAN-SPAM ask for a postal address in each marketing email. Add it in Settings, Profile, and put {{ postal_address }} in the footer.';

    private const UNSUBSCRIBE_REASON = 'Laws such as CAN-SPAM and GDPR ask for an unsubscribe link in marketing email. BYOMailer still adds the one-click List-Unsubscribe header that Gmail and Yahoo require for bulk senders.';

    private const UNSUBSCRIBE_PATTERN = '/\{\{\s*unsubscribe_url\s*\}\}/';

    /**
     * Problems that block a send, keyed by field. Variant B uses the `subject_b` and `html_content_b` keys.
     *
     * @return array<string, string>
     */
    public function problems(?Message $message, AbVariant $variant = AbVariant::A): array
    {
        $isVariantB = $variant === AbVariant::B;
        $suffix = $variant->fieldSuffix();
        $problems = [];

        if ($this->isBlank((string) $message?->subject)) {
            $problems['subject'.$suffix] = $isVariantB ? self::SUBJECT_B_MESSAGE : self::SUBJECT_MESSAGE;
        }

        if ($this->hasBlankBody($message)) {
            $problems['html_content'.$suffix] = $isVariantB ? self::CONTENT_B_MESSAGE : self::CONTENT_MESSAGE;
        }

        return $problems;
    }

    /**
     * Compliance warnings that do not block a send. Variant B uses the
     * `unsubscribe_b` key and skips the postal address, because variant A
     * already reports it.
     *
     * @return array<string, string>
     */
    public function warnings(?Message $message, AbVariant $variant = AbVariant::A): array
    {
        $isVariantB = $variant === AbVariant::B;
        $warnings = [];

        if (! $this->hasBlankBody($message) && ! $this->hasUnsubscribeLink($message)) {
            $warnings['unsubscribe'.$variant->fieldSuffix()] = $isVariantB ? self::UNSUBSCRIBE_B_WARNING : self::UNSUBSCRIBE_WARNING;
        }

        if (! $isVariantB && $message !== null) {
            $warnings += $this->postalAddressWarnings($message->client_id);
        }

        return $warnings;
    }

    /**
     * The postal address warning when the client has no postal address.
     *
     * @return array<string, string>
     */
    public function postalAddressWarnings(?int $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $address = Client::query()->whereKey($clientId)->value('postal_address');

        return $this->isBlank((string) $address) ? ['postal_address' => self::POSTAL_ADDRESS_WARNING] : [];
    }

    private function hasBlankBody(?Message $message): bool
    {
        return $this->isBlank(HtmlToText::convert($message?->html_content))
            && $this->isBlank((string) $message?->text_content);
    }

    private function hasUnsubscribeLink(?Message $message): bool
    {
        if ($message === null) {
            return false;
        }

        return preg_match(self::UNSUBSCRIBE_PATTERN, $message->composedHtml()) === 1
            || preg_match(self::UNSUBSCRIBE_PATTERN, (string) $message->text_content) === 1;
    }

    /**
     * Blank after trim, where non-breaking spaces count as whitespace.
     */
    private function isBlank(string $value): bool
    {
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);

        return ($trimmed ?? trim($value)) === '';
    }
}
