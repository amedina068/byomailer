<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TestMailException extends RuntimeException
{
    public const REASON_NO_IDENTITY = 'no_identity';

    public const REASON_SEND_FAILED = 'send_failed';

    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function noIdentity(): self
    {
        return new self(self::REASON_NO_IDENTITY, 'Please select a sender before sending a test email.');
    }

    public static function sendFailed(Throwable $previous): self
    {
        return new self(
            self::REASON_SEND_FAILED,
            'Failed to send test email. Please check your SES credentials.',
            $previous,
        );
    }
}
