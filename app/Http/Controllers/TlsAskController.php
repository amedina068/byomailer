<?php

namespace App\Http\Controllers;

use App\Models\EmailIdentity;
use App\Services\TrackingDomainVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Caddy on-demand TLS ask endpoint. MUST be called via canonical host —
 * RestrictHostToLinkRoutes does not allowlist this path on customer hosts.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = strtolower(trim((string) $request->query('domain', '')));
        $token = (string) $request->query('token', '');
        $expectedToken = (string) config('services.tracking_proxy.ask_token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            abort(404);
        }

        abort_if($domain === '', 404);

        if (Cache::get(TrackingDomainVerifier::tlsAllowCacheKey($domain))) {
            return response('', 200);
        }

        abort_unless(
            EmailIdentity::query()
                ->withoutGlobalScopes()
                ->where('custom_tracking_domain', $domain)
                ->whereNotNull('custom_tracking_domain_verified_at')
                ->exists(),
            404
        );

        return response('', 200);
    }
}
