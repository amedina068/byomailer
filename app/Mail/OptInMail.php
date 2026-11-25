<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsFromContactMail;
use App\Models\ContactMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class OptInMail extends Mailable
{
    use BuildsFromContactMail;
    use Queueable;
    use SerializesModels;

    public function __construct(public ContactMail $contactMail) {}

    public function envelope(): Envelope
    {
        $extraData = [
            'opt_in_url' => $this->contactMail->opt_in_link ?? '',
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
            'opt_in_url' => $this->contactMail->opt_in_link ?? '',
            'unsubscribe_url' => $this->contactMail->unsubscribe_link ?? '',
            'preferences_url' => $this->contactMail->preferences_link ?? '',
        ]);

        return new Content(
            html: 'emails.opt-in',
            text: 'emails.opt-in_plain',
            with: [
                'htmlContent' => $resolved['htmlContent'],
                'textContent' => $resolved['textContent'],
            ],
        );
    }
}
