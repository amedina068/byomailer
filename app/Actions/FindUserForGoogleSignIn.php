<?php

namespace App\Actions;

use App\Exceptions\GoogleSignInException;
use App\Models\User;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Finds the user for a Google sign-in. Google sign-in never creates a user:
 * a new user comes only from an invitation, the setup, or the sign-up form.
 *
 * A linked Google account finds its user by the Google ID. An unlinked Google
 * account is linked to the user with the same email address, but only when
 * Google verified the address and BYOMailer confirmed it too. The second rule
 * stops a person who signed up with the address of someone else.
 */
class FindUserForGoogleSignIn
{
    public function handle(GoogleUser $googleUser): User
    {
        $googleId = (string) $googleUser->getId();

        if ($googleId === '') {
            throw GoogleSignInException::incomplete();
        }

        $user = User::query()->where('google_id', $googleId)->first()
            ?? $this->link($googleUser, $googleId);

        $this->ensureCanSignIn($user);

        return $user;
    }

    private function link(GoogleUser $googleUser, string $googleId): User
    {
        if (($googleUser->getRaw()['email_verified'] ?? null) !== true) {
            throw GoogleSignInException::unverifiedEmail();
        }

        $user = $this->findByEmail((string) $googleUser->getEmail())
            ?? throw GoogleSignInException::noAccount();

        $this->ensureCanSignIn($user);

        if ($user->email_verified_at === null) {
            throw GoogleSignInException::unconfirmedEmail();
        }

        if ($user->google_id !== null) {
            throw GoogleSignInException::linkedToOtherGoogleAccount();
        }

        $user->forceFill(['google_id' => $googleId])->save();

        return $user;
    }

    /**
     * Compare the addresses without case, in ASCII only. A database collation
     * can match a lookalike character (the Kelvin sign and "k"), so the PHP
     * check must accept each match too.
     */
    private function findByEmail(string $email): ?User
    {
        $email = strtolower($email);

        return User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->get()
            ->first(fn (User $user): bool => strtolower($user->email) === $email);
    }

    private function ensureCanSignIn(User $user): void
    {
        if ($user->isDeactivated()) {
            throw GoogleSignInException::deactivated();
        }

        if ($user->archived_at !== null) {
            throw GoogleSignInException::archived();
        }

        if ($user->invite_expires_at !== null) {
            throw GoogleSignInException::pendingInvitation();
        }
    }
}
