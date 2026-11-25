<?php

namespace App\Exceptions;

use RuntimeException;

class MissingSesPermissionException extends RuntimeException
{
    /** @var array<int, string> */
    private array $missing;

    /**
     * @param  array<int, string>  $missing
     */
    public function __construct(array $missing, int $userId)
    {
        $this->missing = $missing;
        $permissions = implode(', ', $missing);

        parent::__construct("User {$userId} is missing required SES permissions: {$permissions}.");
    }

    /**
     * @return array<int, string>
     */
    public function missingPermissions(): array
    {
        return $this->missing;
    }
}
