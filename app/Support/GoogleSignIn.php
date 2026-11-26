<?php

namespace App\Support;

/**
 * Google sign-in is off by default. The operator turns it on with the
 * `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` values in `.env`.
 */
class GoogleSignIn
{
    public static function isEnabled(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
