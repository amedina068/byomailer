<?php

namespace App\Services;

use App\Exceptions\SsrfException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one request through a custom link domain to the `/up` health route.
 * Cloudflare can show a custom hostname as active while the origin web server
 * sends the host somewhere else. Only an HTTP 2xx from the app proves that
 * the unsubscribe and preference links on the domain work.
 */
class LinkDomainProbe
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 10;

    public function __construct(private SsrfGuard $ssrfGuard) {}

    /**
     * @return array{healthy: bool, reason?: string}
     */
    public function check(string $domain): array
    {
        $url = "https://{$domain}/up";

        try {
            $pinnedAddress = $this->ssrfGuard->pinnedCurlOptions($url);
        } catch (SsrfException) {
            return ['healthy' => false, 'reason' => "{$domain} does not resolve to a public IP address."];
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['curl' => $pinnedAddress])
                ->withUserAgent('BYOMailer-LinkDomainProbe/1.0')
                ->get($url);
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => "{$url} did not answer: ".Str::limit($e->getMessage(), 200)];
        }

        if ($response->successful()) {
            return ['healthy' => true];
        }

        if ($response->redirect()) {
            $location = $response->header('Location') ?: 'an unknown location';

            return ['healthy' => false, 'reason' => "{$url} redirects to {$location}. The web server does not send this host to the app."];
        }

        return ['healthy' => false, 'reason' => "{$url} returned HTTP {$response->status()}. The app must answer with HTTP 200."];
    }
}
