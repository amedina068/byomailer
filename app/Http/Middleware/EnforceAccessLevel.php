<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a write request from a Viewer or from an API token without the
 * write ability. Refuses every request from an API token without the read
 * ability. A route that only reads data through a POST passes `read`.
 */
class EnforceAccessLevel
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $needsWrite = $level !== 'read' && ! $request->isMethodSafe();

        if ($needsWrite && ! $user->hasWriteAccess()) {
            abort(403, 'You have read-only access.');
        }

        if (! $user->hasReadAccess()) {
            abort(403, 'This API token has no read access.');
        }

        return $next($request);
    }
}
