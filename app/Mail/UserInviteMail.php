<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $invitee, public string $inviteUrl)
    {
        $this->invitee->loadMissing('client');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user-invite',
            with: [
                'invitee' => $this->invitee,
                'inviteUrl' => $this->inviteUrl,
                'clientName' => $this->invitee->client?->name,
            ],
        );
    }
}
