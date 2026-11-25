<?php

namespace App\Mail;

use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterBlockedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $missingPermissions
     */
    public function __construct(
        public User $recipient,
        public Newsletter $newsletter,
        public array $missingPermissions,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Newsletter failed: missing SES permissions',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.newsletter-blocked',
            with: [
                'recipient' => $this->recipient,
                'newsletter' => $this->newsletter,
                'missingPermissions' => $this->missingPermissions,
                'settingsUrl' => route('email-credentials.index'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
