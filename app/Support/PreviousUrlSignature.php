<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Checks a signed URL against the previous public URLs of the application.
 *
 * A signed URL holds the host that made it. When the application moves to a
 * new host, a link in an old email comes back through a redirect with the
 * new host, and the normal check fails. This class rebuilds the request on
 * each URL in `app.previous_urls` and checks the signature again.
 */
class PreviousUrlSignature
{
    /**
     * @param  array<int, string>  $ignoreQuery
     */
    public static function isValid(Request $request, array $ignoreQuery = []): bool
    {
        $queryString = (string) $request->server->get('QUERY_STRING');

        foreach (config('app.previous_urls', []) as $previousUrl) {
            $previousRequest = Request::create(
                $previousUrl.'/'.ltrim($request->path(), '/').($queryString === '' ? '' : '?'.$queryString),
                $request->method(),
            );

            $previousRequest->server->set('QUERY_STRING', $queryString);

            if (URL::hasValidSignature($previousRequest, true, $ignoreQuery)) {
                return true;
            }
        }

        return false;
    }
}
