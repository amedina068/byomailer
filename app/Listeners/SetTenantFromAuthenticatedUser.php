<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Make the client of the authenticated user the tenant of the request.
 *
 * The session guard fires `Authenticated` for the dashboard and for a
 * stateful Sanctum request. Sanctum fires `TokenAuthenticated` for an API
 * or MCP request with a bearer token. One listener covers every guard and
 * every route, also the routes that packages register. A logout removes
 * the tenant, so the rest of the request does not keep it.
 */
class SetTenantFromAuthenticatedUser
{
    public function handle(Authenticated|TokenAuthenticated|Logout $event): void
    {
        if ($event instanceof Logout) {
            Context::forgetHidden(Tenant::CONTEXT_KEY);

            return;
        }

        $user = $event instanceof TokenAuthenticated ? $event->token->tokenable : $event->user;

        if ($user instanceof User && $user->client_id !== null) {
            Context::addHidden(Tenant::CONTEXT_KEY, $user->client_id);
        }
    }
}
