<?php

namespace App\Mail;

use App\Concerns\AppliesUtmParameters;
use App\Mail\Concerns\BuildsFromContactMail;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\TestMail;
use App\Services\SequenceStepScheduler;
use App\Support\NamedLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class NewsletterMail extends Mailable
{
    use AppliesUtmParameters;
    use BuildsFromContactMail;
    use Queueable;
    use SerializesModels;

    public function __construct(public ContactMail $contactMail) {}

    public function envelope(): Envelope
    {
        $extraData = [
            'unsubscribe_url' => $this->contactMail->unsubscribe_link ?? '',
            'preferences_url' => $this->contactMail->preferences_link ?? '',
        ];

        return $this->contactMailEnvelope(
            $this->resolvedSubject($extraData),
        );
    }

    public function headers(): Headers
    {
        return $this->contactMailHeaders();
    }

    public function content(): Content
    {
        $resolved = $this->resolveMessageContent([
            'unsubscribe_url' => $this->contactMail->unsubscribe_link ?? '',
            'preferences_url' => $this->contactMail->preferences_link ?? '',
        ]);

        $htmlContent = $resolved['htmlRaw'];

        $mailable = $this->contactMail->mailable;
        if ($mailable instanceof TestMail) {
            $mailable = $mailable->mailable;
        }
        $utmParams = $this->defaultUtmParameters($mailable);

        if ($utmParams !== []) {
            $htmlContent = self::addUtmParametersToHtml($htmlContent, $utmParams);
        }

        $htmlContent = NamedLink::toSesTags($htmlContent);

        return new Content(
            html: 'emails.newsletter',
            text: 'emails.newsletter_plain',
            with: [
                'contact' => $this->contactMail->contact,
                'htmlContent' => new HtmlString($htmlContent),
                'textContent' => $resolved['textContent'],
                'previewText' => $resolved['previewText'],
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function defaultUtmParameters(mixed $mailable): array
    {
        if ($mailable instanceof SequenceStep) {
            return SequenceStepScheduler::defaultUtmParameters($mailable);
        }

        if ($mailable instanceof Newsletter) {
            $subject = Str::slug(trim($mailable->message?->subject ?? '')) ?: 'newsletter';

            return [
                'utm_medium' => 'mail',
                'utm_source' => $subject,
                'utm_campaign' => 'newsletter',
            ];
        }

        return [];
    }
}
