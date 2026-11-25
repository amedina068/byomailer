<?php

namespace App\Models\Concerns;

use App\Scopes\ClientScope;
use App\Support\Tenant;

/**
 * @property int $client_id
 */
trait BelongsToClient
{
    protected static function bootBelongsToClient(): void
    {
        static::addGlobalScope(new ClientScope);

        static::creating(function (self $model): void {
            $model->client_id ??= Tenant::id();
        });
    }
}
