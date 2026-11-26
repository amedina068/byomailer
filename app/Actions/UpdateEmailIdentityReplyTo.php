<?php

namespace App\Actions;

use App\Models\EmailIdentity;

class UpdateEmailIdentityReplyTo
{
    /**
     * Set or clear the Reply-To address of a sender identity. An empty email
     * clears the email and the name.
     */
    public function handle(EmailIdentity $identity, ?string $email, ?string $name): void
    {
        if (blank($email)) {
            $identity->update(['reply_to_email' => null, 'reply_to_name' => null]);

            return;
        }

        $identity->update([
            'reply_to_email' => trim($email),
            'reply_to_name' => blank($name) ? null : trim($name),
        ]);
    }
}
