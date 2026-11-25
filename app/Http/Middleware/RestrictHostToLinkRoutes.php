<?php

namespace App\Http\Middleware;

use App\Models\EmailIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom link domains (e.g. mail.customer.com) are CNAME'd to our Cloudflare
 * for SaaS fallback origin. On those hosts only the public contact-facing
 * link routes may be served — never the dashboard, auth, API, or vendor
 * routes. Requests on the canonical app host are unaffected.
 */
class RestrictHostToLinkRoutes
{
    /**
     * Path patterns permitted on a custom link domain: the public
     * contact-facing link routes, the Vite asset bundle the Inertia pages
     * for those routes load (`public/build/*` — static, public, safe to
     * serve on any host). The `/up` health endpoint is open on each host.
     */
    private const ALLOWED_PATHS = [
        'unsubscribe/*',
        'one-click-unsubscribe/*',
        'resubscribe/*',
        'preferences/*',
        'opt-in/*',
        'build/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // The container health check calls /up on localhost, not on the host of APP_URL.
        if ($request->is('up')) {
            return $next($request);
        }

        $host = $request->getHost();

        if ($host === $this->canonicalHost()) {
            return $next($request);
        }

        if (! $this->isVerifiedLinkDomain($host)) {
            abort(404);
        }

        if (! $request->is(...self::ALLOWED_PATHS)) {
            abort(404);
        }

        return $next($request);
    }

    private function canonicalHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    private function isVerifiedLinkDomain(string $host): bool
    {
        return Cache::remember(
            "link-domain-host:{$host}",
            60,
            fn (): bool => EmailIdentity::withoutGlobalScopes()
                ->where('custom_link_domain', $host)
                ->whereNotNull('custom_link_domain_verified_at')
                ->exists()
        );
    }
}
