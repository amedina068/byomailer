<?php

namespace App\Support\Webhooks;

class WebhookSignature
{
    public const HEADER = 'X-Byomailer-Signature';

    /**
     * Build the signature header value for a webhook delivery.
     *
     * Receivers verify with: hash_hmac('sha256', timestamp.'.'.rawBody, secret)
     * compared timing-safe, within a replay window of the X-Byomailer-Timestamp header.
     */
    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
