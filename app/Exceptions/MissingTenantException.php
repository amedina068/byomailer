<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A query on a client-scoped model ran with no tenant.
 */
class MissingTenantException extends RuntimeException
{
    public function __construct(public readonly string $model)
    {
        parent::__construct(sprintf(
            'The [%s] query has no tenant. Run the code inside Tenant::run() or use withoutGlobalScopes() with an explicit client_id.',
            $model,
        ));
    }
}
