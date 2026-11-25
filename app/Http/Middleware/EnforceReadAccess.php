<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read check of `EnforceAccessLevel` for a POST route that only reads
 * data, for example the audience preview.
 *
 * A route removes `EnforceAccessLevel` with `withoutMiddleware()` and adds
 * this middleware. It is a separate class because Laravel 13.43 and later
 * also remove `EnforceAccessLevel:read` when a route removes
 * `EnforceAccessLevel`.
 */
class EnforceReadAccess
{
    public function __construct(private EnforceAccessLevel $accessLevel) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->accessLevel->handle($request, $next, 'read');
    }
}
