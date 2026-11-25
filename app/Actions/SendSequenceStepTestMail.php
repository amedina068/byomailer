<?php

namespace App\Actions;

use App\Exceptions\TestMailException;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\SequenceStep;
use App\Models\User;

class SendSequenceStepTestMail extends TestMailSender
{
    /**
     * @throws TestMailException
     */
    public function handle(User $user, SequenceStep $step, ?EmailIdentity $identityOverride = null): ContactMail
    {
        $step->loadMissing(['message.emailIdentity', 'sequence']);

        $message = $step->message;
        $emailIdentity = $identityOverride ?? $message?->emailIdentity;

        if ($message === null || $emailIdentity === null) {
            throw TestMailException::noIdentity();
        }

        return $this->dispatchTestMail(
            $user,
            $step,
            $message,
            $emailIdentity,
            $step->sequence?->content_variables ?? [],
        );
    }
}
