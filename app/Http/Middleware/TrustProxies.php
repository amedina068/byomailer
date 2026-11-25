<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;

/**
 * Reads the trusted proxies from `trustedproxies.proxies` for each request.
 * The `TRUSTED_PROXIES` setting sets the list. The default is the
 * Cloudflare ranges.
 */
class TrustProxies extends BaseTrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return config('trustedproxies.proxies');
    }
}
