<?php

namespace App\Services;

use App\Exceptions\AwsCredentialsException;
use App\Models\EmailIdentity;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackingDomainVerifier
{
    public const TLS_ALLOW_CACHE_TTL_SECONDS = 600;

    public const WARM_HTTP_TIMEOUT_SECONDS = 35;

    private ?Closure $dnsResolver = null;

    public function __construct(
        private SesConfigurationSetService $configService,
        private SesCredentials $credentials,
    ) {}

    public static function tlsAllowCacheKey(string $domain): string
    {
        return 'tracking-domain-tls-allow:'.strtolower($domain);
    }

    /**
     * Set a custom DNS resolver for testing.
     *
     * @param  Closure(string, int): (array<int, array<string, mixed>>|false)  $resolver
     */
    public function setDnsResolver(Closure $resolver): void
    {
        $this->dnsResolver = $resolver;
    }

    /**
     * @return array{verified: bool, error?: string}
     */
    public function verify(EmailIdentity $identity): array
    {
        $credential = $identity->user->sesCredential;

        if (! $credential) {
            return ['verified' => false, 'error' => 'No SES credentials found.'];
        }

        $expectedIp = config('services.tracking_proxy.ip');

        if (! $expectedIp) {
            return ['verified' => false, 'error' => 'Tracking proxy IP not configured.'];
        }

        $records = $this->resolveDns($identity->custom_tracking_domain, DNS_A);

        if (! $records) {
            return ['verified' => false];
        }

        $ipMatches = false;

        foreach ($records as $record) {
            if (isset($record['ip']) && $record['ip'] === $expectedIp) {
                $ipMatches = true;
                break;
            }
        }

        if (! $ipMatches) {
            return ['verified' => false];
        }

        if ($identity->custom_tracking_domain_verified_at) {
            return ['verified' => true];
        }

        return Cache::lock("tracking-domain-activate:{$identity->id}", 30)->get(
            fn () => $this->activate($identity->fresh())
        ) ?: ['verified' => false, 'error' => 'Could not acquire lock. Another verification may be in progress.'];
    }

    /**
     * Activate the custom tracking domain on SES and mark it as verified.
     *
     * @return array{verified: bool, error?: string}
     */
    private function activate(EmailIdentity $identity): array
    {
        if ($identity->custom_tracking_domain_verified_at) {
            return ['verified' => true];
        }

        if (! $identity->configuration_set_name) {
            return [
                'verified' => false,
                'error' => 'No configuration set found.',
            ];
        }

        if (! $identity->custom_tracking_domain) {
            return [
                'verified' => false,
                'error' => 'No tracking domain configured.',
            ];
        }

        $credential = $identity->user->sesCredential;

        if (! $credential) {
            return ['verified' => false, 'error' => 'No SES credentials found.'];
        }

        $warm = $this->warmTls($identity->custom_tracking_domain);

        if (! $warm['ok']) {
            return ['verified' => false, 'error' => $warm['error']];
        }

        try {
            $aws = $this->credentials->for($credential);
        } catch (AwsCredentialsException $exception) {
            return ['verified' => false, 'error' => $exception->getMessage()];
        }

        $result = $this->configService->putTrackingOptions(
            $identity->configuration_set_name,
            $identity->custom_tracking_domain,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken()
        );

        if (! $result['success']) {
            Cache::forget(self::tlsAllowCacheKey($identity->custom_tracking_domain));

            return [
                'verified' => false,
                'error' => $result['error'],
            ];
        }

        $identity->update(['custom_tracking_domain_verified_at' => now()]);

        return ['verified' => true];
    }

    /**
     * Caddy must serve /healthz locally (no proxy to AWS) — otherwise
     * a 200 from awstrack.me's catch-all would falsely confirm cert issuance.
     *
     * @return array{ok: bool, error?: string}
     */
    private function warmTls(string $domain): array
    {
        if ($domain === '') {
            return ['ok' => false, 'error' => 'No tracking domain configured.'];
        }

        $cacheKey = self::tlsAllowCacheKey($domain);
        Cache::put($cacheKey, true, self::TLS_ALLOW_CACHE_TTL_SECONDS);

        try {
            $response = Http::timeout(self::WARM_HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'BYOMailer-TLS-Warmer/1.0'])
                ->get("https://{$domain}/healthz");
        } catch (Throwable $e) {
            Cache::forget($cacheKey);

            Log::warning('Tracking domain TLS warm exception', [
                'domain' => $domain,
                'exception' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => 'Could not reach tracking domain over HTTPS. Check that DNS has propagated and try again in a minute.',
            ];
        }

        if (! $response->successful()) {
            Cache::forget($cacheKey);

            Log::warning('Tracking domain TLS warm non-2xx', [
                'domain' => $domain,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'error' => 'Tracking domain HTTPS check failed (status '.$response->status().'). Retry in a minute; certificate issuance can take a moment.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    private function resolveDns(string $hostname, int $type): array|false
    {
        if ($this->dnsResolver) {
            return ($this->dnsResolver)($hostname, $type);
        }

        return dns_get_record($hostname, $type);
    }
}
