<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Illuminate\Http\Response;

/**
 * RFC 8058 one-click unsubscribe endpoint.
 *
 * This is the URL advertised in `List-Unsubscribe`, paired with
 * `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. Gmail, Yahoo and Apple
 * Mail POST here with the body `List-Unsubscribe=One-Click` and no cookies,
 * no CSRF token and no redirect following, so the route is exempt from CSRF
 * verification and the response is a plain 200. The action is idempotent.
 *
 * Bulk senders are required to honour this. A 405 or 419 here is a direct
 * negative reputation signal with the provider.
 */
class OneClickUnsubscribeController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveContactMailFromLink($contactMail);

        if (! $this->isTestMail($contactMailModel)) {
            $this->applyUnsubscribe($contactMailModel);
        }

        return response('Unsubscribed', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
