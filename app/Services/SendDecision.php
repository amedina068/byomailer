<?php

namespace App\Services;

class SendDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $message = null,
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(allowed: true);
    }

    public static function block(string $message, string $reason): self
    {
        return new self(allowed: false, message: $message, reason: $reason);
    }

    /**
     * The validation error key a caller reports a block under.
     */
    public function errorKey(): string
    {
        return 'ses_account';
    }
}
