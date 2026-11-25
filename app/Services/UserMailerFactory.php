<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;

class UserMailerFactory
{
    public function __construct(
        private MailManager $mailManager,
        private SesClientManager $sesClients,
    ) {}

    public function mailerFor(User $user): PendingMail
    {
        if (! config('mail.use_ses_api')) {
            return new PendingMail($this->mailManager->mailer(config('mail.default')));
        }

        return new PendingMail($this->mailManager->build([
            'name' => 'ses-user-'.$user->id,
            ...$this->sesClients->mailerConfigFor($user),
        ]));
    }
}
