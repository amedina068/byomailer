<?php

namespace App\Mail\Concerns;

use App\Models\Client;
use App\Models\ContactMail;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\TestMail;
use App\Support\ContentSlot;
use App\Support\HtmlToText;
use App\Support\ReplacesBlockVariables;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\HtmlString;

/**
 * Shared logic for mailables that send from a ContactMail record.
 *
 * Expects `$this->contactMail` (ContactMail) on the mailable.
 */
trait BuildsFromContactMail
{
    use ReplacesBlockVariables;

    /**
     * Build Headers that route the email through the message's identity configuration set.
     *
     * SES reads `X-SES-CONFIGURATION-SET` from raw MIME when sending via `SendRawEmail`
     * (Laravel's `ses` transport). This replaces the previous behavior of setting the
     * configuration set as the identity default, which clobbered BYO identities.
     */
    protected function contactMailHeaders(): Headers
    {
        $this->loadMessageRelationship();

        $message = $this->resolveMessage();
        $message->loadMissing('emailIdentity');

        $headers = [
            'X-SES-CONFIGURATION-SET' => $message->emailIdentity->configuration_set_name,
        ];

        // RFC 8058: the advertised URI must accept the provider's POST, so it
        // points at the CSRF-exempt one-click route, not the confirmation page.
        $oneClickUrl = $this->contactMail->one_click_unsubscribe_link;

        if ($oneClickUrl) {
            $headers['List-Unsubscribe'] = "<{$oneClickUrl}>";
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $headers);
    }

    /**
     * Build an Envelope with from address, the identity's Reply-To, and contact_mail_uuid metadata.
     */
    protected function contactMailEnvelope(string $subject): Envelope
    {
        return new Envelope(
            subject: $subject,
            from: new Address($this->contactMail->from_email, $this->contactMail->from_name),
            replyTo: $this->replyToAddresses(),
            metadata: array_filter([
                'contact_mail_uuid' => $this->contactMail->uuid,
                'client_id' => (string) $this->contactMail->client_id,
            ]),
        );
    }

    /**
     * The Reply-To address of the message's sender identity, read at send time.
     *
     * @return array<int, Address>
     */
    protected function replyToAddresses(): array
    {
        $this->loadMessageRelationship();

        $identity = $this->resolveMessage()?->loadMissing('emailIdentity')->emailIdentity;

        if (blank($identity?->reply_to_email)) {
            return [];
        }

        return [new Address($identity->reply_to_email, $identity->reply_to_name)];
    }

    /**
     * Load the Message from the contactMail, merge template data with extra data,
     * and replace variables in html/text content.
     *
     * @param  array<string, string>  $extraTemplateData
     * @return array{htmlContent: HtmlString, textContent: string, message: ?Message, htmlRaw: string, previewText: string}
     */
    protected function resolveMessageContent(array $extraTemplateData = []): array
    {
        $this->loadMessageRelationship();

        $message = $this->resolveMessage();
        $templateData = array_merge($this->contactMail->template_data ?? [], $extraTemplateData);

        $htmlContent = $message?->composedHtml() ?? '';
        $previewText = (string) ($message?->preview_text ?? '');

        $client = $this->resolveClient();

        // Expand the blocks in the HTML first. The plain part is derived from
        // the expanded HTML, so a block cannot land in `text/plain` with its
        // tags intact. A stored text body gets the text rendering of the block.
        $htmlContent = $this->replaceBlockVariables($htmlContent, $client);
        $previewText = $this->replaceBlockVariablesAsText($previewText, $client);

        $storedText = $message?->currentTemplate() === null && $message?->text_content !== null
            ? $this->replaceBlockVariablesAsText($message->text_content, $client)
            : null;

        if ($message?->usesLiquid()) {
            // Liquid code can sit between table rows, where the HTML to text
            // conversion moves it. The text part comes from the rendered HTML.
            $htmlContent = $message->render($htmlContent, $templateData);
            $textContent = $storedText === null ? HtmlToText::convert($htmlContent) : $message->render($storedText, $templateData, html: false);
            $previewText = $message->render($previewText, $templateData, html: false);
        } else {
            $textContent = $storedText ?? HtmlToText::convert($htmlContent);

            // Replace contact template variables ({{ name }}, {{ email }}, etc.)
            // The HTML part gets escaped values. The text part keeps raw values.
            if ($message) {
                $htmlContent = $message->render($htmlContent, $templateData);
                $textContent = $message->render($textContent, $templateData, html: false);
                $previewText = $message->render($previewText, $templateData, html: false);
            }
        }

        $htmlContent = ContentSlot::unwrap($htmlContent);

        return [
            'htmlContent' => new HtmlString($htmlContent),
            'textContent' => $textContent,
            'message' => $message,
            'htmlRaw' => $htmlContent,
            'previewText' => trim($previewText),
        ];
    }

    /**
     * Replace template variables in the contactMail subject.
     *
     * @param  array<string, string>  $extraTemplateData
     */
    protected function resolvedSubject(array $extraTemplateData = []): string
    {
        $this->loadMessageRelationship();

        $message = $this->resolveMessage();
        $templateData = array_merge($this->contactMail->template_data ?? [], $extraTemplateData);

        $subject = $this->contactMail->subject;

        // A subject line is plain text, so a block resolves to its text form.
        $client = $this->resolveClient();
        $subject = $this->replaceBlockVariablesAsText($subject, $client);

        return $message ? $message->render($subject, $templateData, html: false) : $subject;
    }

    /**
     * Resolve the Client that owns the blocks for variable replacement.
     */
    protected function resolveClient(): Client
    {
        return $this->contactMail->client;
    }

    /**
     * Eagerly load the message relationship based on the mailable type.
     */
    protected function loadMessageRelationship(): void
    {
        if ($this->contactMail->mailable_type === SequenceStep::class) {
            $this->contactMail->loadMissing(['mailable.message', 'mailable.sequence', 'message']);
        } elseif ($this->contactMail->mailable_type === Newsletter::class) {
            $this->contactMail->loadMissing(['mailable.message', 'message']);
        } elseif ($this->contactMail->mailable_type === TestMail::class) {
            $this->contactMail->loadMissing(['mailable.mailable', 'message']);
        } else {
            $this->contactMail->loadMissing('message');
        }
    }

    /**
     * Resolve the Message model — prefer the mailable's message, fall back to contactMail's direct message.
     */
    protected function resolveMessage(): ?Message
    {
        return $this->contactMail->mailable?->message ?? $this->contactMail->message;
    }
}
