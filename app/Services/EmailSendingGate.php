<?php

namespace App\Services;

use App\Models\User;

class EmailSendingGate
{
    public const SES_SENDING_DISABLED_REASON = 'ses_sending_disabled';

    public const USER_DEACTIVATED_REASON = 'user_deactivated';

    public const USER_DEACTIVATED_MESSAGE = 'The owner of this BYOMailer instance deactivated this account. BYOMailer does not send email for it.';

    public const SES_SENDING_DISABLED_MESSAGE = 'Amazon SES has paused sending on your AWS account. Open the SES console to see why. Sending starts again after SES turns it back on.';

    /**
     * Decide whether a user can send production email. A deactivated user and
     * a paused SES account block the send. The recipient count is kept
     * so callers do not change when a new check needs it.
     */
    public function evaluate(User $user, int $recipientCount = 0): SendDecision
    {
        if ($user->isDeactivated()) {
            return SendDecision::block(self::USER_DEACTIVATED_MESSAGE, self::USER_DEACTIVATED_REASON);
        }

        if ($this->sesSendingDisabled($user)) {
            return SendDecision::block(self::SES_SENDING_DISABLED_MESSAGE, self::SES_SENDING_DISABLED_REASON);
        }

        return SendDecision::allow();
    }

    /**
     * SES paused the account at the last quota fetch. A credential that was never
     * fetched does not block, because its sending_enabled column is only a default.
     */
    private function sesSendingDisabled(User $user): bool
    {
        $credential = $user->sesCredential;

        return $credential !== null
            && $credential->quota_fetched_at !== null
            && ! $credential->sending_enabled;
    }
}
