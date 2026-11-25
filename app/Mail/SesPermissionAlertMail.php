<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SesPermissionAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $missingPermissions
     */
    public function __construct(
        public User $recipient,
        public array $missingPermissions,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: missing SES permissions',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ses-permission-alert',
            with: [
                'recipient' => $this->recipient,
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
