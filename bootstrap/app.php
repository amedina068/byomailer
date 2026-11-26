<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogoutDeactivatedUser;
use App\Http\Middleware\RedirectLoopbackHost;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\RestrictHostToLinkRoutes;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignatureWithPreviousUrls;
use App\Models\MailList;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app TrustProxies reads `trustedproxies.proxies` for each
        // request, so the TRUSTED_PROXIES setting can change the list. The
        // default is the Cloudflare ranges. Then $request->ip() is the real
        // visitor and the per-IP limits of the link routes work.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);

        $middleware->prepend(RestrictHostToLinkRoutes::class);

        // 127.0.0.1 and [::1] go to the APP_URL host, before the host check
        // above answers 404.
        $middleware->prepend(RedirectLoopbackHost::class);

        // Refuse frames from other sites and MIME sniffing on each response.
        $middleware->append(AddSecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // `one-click-unsubscribe/*` is the RFC 8058 endpoint. Mailbox providers
        // POST to it with no cookies and no token, so CSRF verification would
        // answer 419 and Gmail/Yahoo would count that as a failed unsubscribe.
        // The human-facing `unsubscribe/*` form stays CSRF protected.
        $middleware->validateCsrfTokens(except: [
            'webhooks/ses',
            'one-click-unsubscribe/*',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RedirectToSetup::class,
            LogoutDeactivatedUser::class,
            // Ends a web session when the password of its user changed, for
            // example with byomailer:user:password.
            AuthenticateSession::class,
        ]);

        // Laravel sorts the route middleware by priority, and it moves `auth`
        // before each middleware that is not in the priority list. Without this
        // line, `auth` sends a deactivated user to the login form with no message.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, LogoutDeactivatedUser::class);
        // Signed links in emails sent before a host move keep the old host.
        // This `signed` also accepts the URLs in `app.previous_urls`.
        $middleware->alias([
            'signed' => ValidateSignatureWithPreviousUrls::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API and the MCP server always answer errors with JSON. Without
        // this, a client that does not send `Accept: application/json` gets
        // a redirect to the login page or an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*', 'mcp') || $request->expectsJson(),
        );

        // Name the missing record, not the PHP model class.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?JsonResponse {
            $missing = $exception->getPrevious();

            if (! $missing instanceof ModelNotFoundException || ! $request->is('api/*')) {
                return null;
            }

            $record = $missing->getModel() === MailList::class
                ? 'list'
                : Str::snake(class_basename($missing->getModel()), ' ');

            return response()->json([
                'message' => "No {$record} with ID ".implode(', ', $missing->getIds()).' exists in this account.',
            ], 404);
        });
    })->create();
