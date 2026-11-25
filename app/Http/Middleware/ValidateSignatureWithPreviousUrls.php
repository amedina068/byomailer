<?php

namespace App\Http\Middleware;

use App\Support\PreviousUrlSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `signed` middleware, with one addition: an absolute signature that was
 * made on a previous application URL (`app.previous_urls`) is also valid.
 * Links in emails sent before a host move then keep working.
 */
class ValidateSignatureWithPreviousUrls extends ValidateSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next, ...$args): Response
    {
        [$relative, $ignore] = $this->parseArguments($args);

        if ($request->hasValidSignatureWhileIgnoring($ignore, ! $relative)) {
            return $next($request);
        }

        if (! $relative && PreviousUrlSignature::isValid($request, $ignore)) {
            return $next($request);
        }

        throw new InvalidSignatureException;
    }
}
