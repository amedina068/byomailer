<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A Google sign-in that BYOMailer refuses. The login page shows the message.
 */
class GoogleSignInException extends RuntimeException
{
    public static function incomplete(): self
    {
        return new self('The Google sign-in did not complete. Try again.');
    }

    public static function unverifiedEmail(): self
    {
        return new self('Google did not verify this email address. Log in with your password.');
    }

    public static function noAccount(): self
    {
        return new self('No account uses this email address. Ask a team admin for an invitation.');
    }

    public static function pendingInvitation(): self
    {
        return new self('Accept your invitation first. Open the link in the invitation email.');
    }

    public static function unconfirmedEmail(): self
    {
        return new self('This account has no confirmed email address. Log in with your password.');
    }

    public static function linkedToOtherGoogleAccount(): self
    {
        return new self('This account is linked to a different Google account.');
    }

    public static function archived(): self
    {
        return new self('This account is archived.');
    }

    public static function deactivated(): self
    {
        return new self(User::DEACTIVATED_MESSAGE);
    }
}
