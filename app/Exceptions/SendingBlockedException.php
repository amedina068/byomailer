<?php

namespace App\Exceptions;

use RuntimeException;

class SendingBlockedException extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly ?string $reason = null)
    {
        parent::__construct("Production email sending is blocked for user {$userId}: ".($reason ?? 'unknown reason').'.');
    }
}
