<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add the browser security headers to each response.
 *
 * No page of the app is made to be shown in a frame of another site, so the
 * headers refuse each frame. This stops clickjacking on the dashboard and on
 * the unsubscribe and preference pages. A header that a route already set is
 * kept.
 *
 * The middleware sends no Content-Security-Policy. The email preview is a
 * srcdoc frame, and a srcdoc frame inherits the policy of its parent page.
 */
class AddSecurityHeaders
{
    /**
     * @var array<string, string>
     */
    public const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
