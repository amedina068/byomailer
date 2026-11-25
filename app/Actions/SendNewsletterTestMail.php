<?php

namespace App\Actions;

use App\Exceptions\TestMailException;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;

class SendNewsletterTestMail extends TestMailSender
{
    /**
     * @throws TestMailException
     */
    public function handle(User $user, Newsletter $newsletter, ?EmailIdentity $identityOverride = null): ContactMail
    {
        $newsletter->loadMissing(['message.emailIdentity']);

        $message = $newsletter->message;
        $emailIdentity = $identityOverride ?? $message?->emailIdentity;

        if ($message === null || $emailIdentity === null) {
            throw TestMailException::noIdentity();
        }

        return $this->dispatchTestMail(
            $user,
            $newsletter,
            $message,
            $emailIdentity,
            $newsletter->content_variables ?? [],
        );
    }
}
