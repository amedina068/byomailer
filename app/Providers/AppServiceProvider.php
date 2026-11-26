<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Http\Responses\RegisterResponse;
use App\Models\Contact;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Scopes\ClientScope;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesCredentials;
use App\Services\SesTenantService;
use Aws\Ses\SesClient;
use Aws\Sns\MessageValidator;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (class_exists(SesClient::class)) {
            $this->app->scoped(SesCredentials::class, fn ($app) => new SesCredentials($app['config']));
            $this->app->scoped(SesClientManager::class, fn ($app) => new SesClientManager($app['config'], $app->make(SesCredentials::class)));
            $this->app->scoped(SesBulkEmailService::class, fn ($app) => new SesBulkEmailService($app->make(SesClientManager::class), $app->make(SesTenantService::class), $app->make(SesCredentials::class)));
        }

        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        $this->app->bind(MessageValidator::class, fn (): MessageValidator => new MessageValidator(
            $this->fetchSnsSigningCertificate(...)
        ));
    }

    /**
     * Fetch an SNS signing certificate. The validator checks that the URL is an
     * https URL on an `sns.*.amazonaws.com` host before it calls this method.
     * A failed fetch throws, so SNS gets a 5xx and sends the message again.
     */
    protected function fetchSnsSigningCertificate(string $certificateUrl): string
    {
        return Cache::remember(
            'sns-signing-certificate:'.sha1($certificateUrl),
            now()->addDay(),
            fn (): string => Http::timeout(5)->get($certificateUrl)->throw()->body(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Contact::observe(ContactObserver::class);

        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->refuseInactiveUsers();
        $this->configureApiDocumentation();
    }

    /**
     * Each API route needs a personal API token, sent as a bearer token.
     */
    protected function configureApiDocumentation(): void
    {
        Scramble::configure()->withDocumentTransformers(
            fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer'))
        );
    }

    /**
     * Keep a deactivated, archived or invited user out of every guard.
     *
     * The session guard finds the user through the `active-eloquent` provider.
     * Sanctum reads the user of an API token from the token row and skips the
     * provider, so it needs its own check. The API and the MCP server both use
     * Sanctum tokens. Sanctum keeps only the last callback, so this one callback
     * holds every rule. The login form and the invitation link do their own checks.
     */
    protected function refuseInactiveUsers(): void
    {
        Auth::provider('active-eloquent', fn ($app, array $config): ActiveUserProvider => new ActiveUserProvider($app['hash'], $config['model']));

        Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid): bool => $isValid
            && ! ($token->tokenable instanceof User
                && ($token->tokenable->isDeactivated()
                    || $token->tokenable->archived_at !== null
                    || $token->tokenable->invite_expires_at !== null)));
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ses-sending', function (object $job) {
            $maxSendRate = max(1, app(SesClientManager::class)->getMaxSendRateFor($job->user));
            $jobsPerSecond = max(1, (int) ceil($maxSendRate / 50));

            return Limit::perSecond($jobsPerSecond)->by($job->user->id);
        });

        RateLimiter::for('newsletter-test-mail', fn (Request $request) => Limit::perMinute(5)->by(optional($request->user())->id ?: $request->ip()));

        RateLimiter::for('newsletter-preview', fn (Request $request) => Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip()));

        RateLimiter::for('tls-ask', fn (Request $request) => Limit::perMinute(20)->by(
            (string) $request->query('domain', $request->ip())
        ));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(max(1, (int) config('app.api_rate_limit')))
            ->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('webhook-test-ping', fn (Request $request) => Limit::perMinute(10)->by(optional($request->user())->client_id ?: $request->ip()));
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        ClientScope::preventQueriesWithoutTenant(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
