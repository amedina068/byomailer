<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app answers only on the host of APP_URL (RestrictHostToLinkRoutes). A
 * person who opens http://127.0.0.1:8080 or http://[::1]:8080 gets the same
 * page on the APP_URL host with the same port, not a 404.
 *
 * The health check answers on any host, so this middleware does not touch it.
 */
class RedirectLoopbackHost
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = $request->getHost();

        if ($appHost === ''
            || ! in_array($host, self::LOOPBACK_HOSTS, true)
            || in_array($appHost, self::LOOPBACK_HOSTS, true)
            || ! $request->isMethodSafe()
            || $request->is('up')) {
            return $next($request);
        }

        $port = $request->getPort();
        $isDefaultPort = ($request->isSecure() && $port === 443) || (! $request->isSecure() && $port === 80);

        return redirect()->to(
            $request->getScheme().'://'.$appHost.($isDefaultPort ? '' : ':'.$port).$request->getRequestUri()
        );
    }
}
