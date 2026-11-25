<?php

namespace App\Http\Middleware;

use App\Models\ContactMail;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run a contact-facing link route as the client of the mail in the link.
 *
 * The unsubscribe, resubscribe, opt-in and preference links have no
 * authenticated user. The `{contactMail}` uuid in the path names the mail,
 * and the client of that mail is the tenant of the request. An unknown uuid
 * gets no tenant, and the controller answers 404.
 */
class SetTenantFromContactMailLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = ContactMail::withoutGlobalScopes()
            ->where('uuid', (string) $request->route('contactMail'))
            ->value('client_id');

        if ($clientId === null) {
            return $next($request);
        }

        return Tenant::run($clientId, fn (): Response => $next($request));
    }
}
