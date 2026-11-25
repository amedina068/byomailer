<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SequenceBlockedDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $recipient,
        public int $blockedCount,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Daily digest: sequence emails blocked by missing permissions',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sequence-blocked-digest',
            with: [
                'recipient' => $this->recipient,
                'blockedCount' => $this->blockedCount,
                'settingsUrl' => route('email-credentials.index'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
