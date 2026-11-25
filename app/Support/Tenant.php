<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * The current tenant (client) of the code that runs now.
 *
 * The client id is a hidden Context value. A request sets it when a user
 * authenticates, and Laravel copies it into the payload of each job that the
 * request dispatches. Code with no request (a command, the scheduler, a
 * webhook) runs its tenant work inside Tenant::run().
 */
class Tenant
{
    public const CONTEXT_KEY = 'client_id';

    /**
     * The id of the current client, or null when no tenant is set.
     */
    public static function id(): ?int
    {
        return Context::getHidden(self::CONTEXT_KEY) ?? Auth::user()?->client_id;
    }

    /**
     * Run the callback as the given client, then restore the previous tenant.
     *
     * Jobs that the callback dispatches carry this client in their payload.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function run(int $clientId, callable $callback): mixed
    {
        return Context::scope($callback, hidden: [self::CONTEXT_KEY => $clientId]);
    }
}
