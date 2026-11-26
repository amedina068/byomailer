<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email of the `send-test-email` tool of the instance-admin MCP server.
 * It proves that the AWS keys, the region and a verified sender identity of
 * an account can send through Amazon SES.
 */
class SesTestMail extends Mailable
{
    public function __construct(public string $fromEmail, public string $fromName, public string $appUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: 'BYOMailer test email',
        );
    }

    public function content(): Content
    {
        $appUrl = e($this->appUrl);

        return new Content(htmlString: "<p>This is a test email from the BYOMailer instance at {$appUrl}.</p>"
            .'<p>Amazon SES sent it. The AWS keys, the region and the sender identity of this account work.</p>');
    }
}
