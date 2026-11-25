<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->archived_at !== null) {
            abort(403, 'Your account has been archived.');
        }

        if ($user?->invite_expires_at !== null) {
            abort(403, 'Complete your invitation to access this area.');
        }

        return $next($request);
    }
}
