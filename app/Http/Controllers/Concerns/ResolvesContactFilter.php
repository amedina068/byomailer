<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\ContactResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ResolvesContactFilter
{
    /**
     * @param  list<string>  $allowed
     */
    protected function resolveFilter(Request $request, array $allowed): string
    {
        $filter = (string) $request->query('filter');

        return in_array($filter, $allowed, true) ? $filter : 'all';
    }

    protected function paginateContacts(
        Builder $query,
        Request $request,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn ($contact) => ContactResource::make($contact)->resolve($request));
    }
}
