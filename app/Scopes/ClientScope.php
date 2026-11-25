<?php

namespace App\Scopes;

use App\Exceptions\MissingTenantException;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limit a query to the rows of the current tenant.
 *
 * A query with no tenant matches no rows, so a forgotten tenant can never
 * read the rows of another client. In strict mode it also throws, so the
 * developer finds the fault in a test. In production it is reported.
 */
class ClientScope implements Scope
{
    protected static bool $preventsQueriesWithoutTenant = false;

    /**
     * Throw on a query with no tenant, like Model::preventLazyLoading().
     */
    public static function preventQueriesWithoutTenant(bool $value = true): void
    {
        static::$preventsQueriesWithoutTenant = $value;
    }

    public static function preventsQueriesWithoutTenant(): bool
    {
        return static::$preventsQueriesWithoutTenant;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $clientId = Tenant::id();

        if ($clientId === null) {
            $exception = new MissingTenantException($model::class);

            throw_if(static::$preventsQueriesWithoutTenant, $exception);

            report($exception);

            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->qualifyColumn('client_id'), $clientId);
    }
}
