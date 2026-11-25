<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomTrackingDomainRequest;
use App\Models\EmailIdentity;
use App\Services\SesConfigurationSetService;
use App\Services\SesCredentials;
use App\Services\TrackingDomainVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CustomTrackingDomainController extends Controller
{
    public function update(
        UpdateCustomTrackingDomainRequest $request,
        EmailIdentity $emailIdentity,
        SesConfigurationSetService $configService,
        SesCredentials $sesCredentials,
    ): JsonResponse {
        $user = $request->user();
        $credential = $user->sesCredential;

        if (! $credential) {
            return response()->json(['error' => 'No AWS credentials found. Add your AWS credentials in Settings.'], 404);
        }

        if (! $emailIdentity->configuration_set_name) {
            return response()->json(['error' => 'No configuration set found for this identity.'], 404);
        }

        $aws = $sesCredentials->for($credential);
        $cacheKey = "tracking-domain-check:{$emailIdentity->id}";
        $previousDomain = $emailIdentity->custom_tracking_domain;

        if ($request->boolean('enabled')) {
            $domain = $request->validated('custom_tracking_domain');

            if ($emailIdentity->custom_tracking_domain_verified_at && $previousDomain !== $domain) {
                $configService->removeTrackingOptions(
                    $emailIdentity->configuration_set_name,
                    $aws->getAccessKeyId(),
                    $aws->getSecretKey(),
                    $credential->region,
                    $aws->getSecurityToken()
                );
            }

            $emailIdentity->update([
                'custom_tracking_domain' => $domain,
                'custom_tracking_domain_verified_at' => null,
            ]);

            Cache::forget($cacheKey);

            if ($previousDomain && $previousDomain !== $domain) {
                $this->forgetTlsAllow($previousDomain);
            }

            return response()->json([
                'success' => true,
                'a_record_ip' => config('services.tracking_proxy.ip'),
            ]);
        }

        if ($emailIdentity->custom_tracking_domain_verified_at) {
            $result = $configService->removeTrackingOptions(
                $emailIdentity->configuration_set_name,
                $aws->getAccessKeyId(),
                $aws->getSecretKey(),
                $credential->region,
                $aws->getSecurityToken()
            );

            if (! $result['success']) {
                return response()->json([
                    'error' => 'Failed to remove custom tracking domain: '.$result['error'],
                ], 422);
            }
        }

        $emailIdentity->update([
            'custom_tracking_domain' => null,
            'custom_tracking_domain_verified_at' => null,
        ]);

        Cache::forget($cacheKey);

        if ($previousDomain) {
            $this->forgetTlsAllow($previousDomain);
        }

        return response()->json(['success' => true]);
    }

    private function forgetTlsAllow(string $domain): void
    {
        Cache::forget(TrackingDomainVerifier::tlsAllowCacheKey($domain));
    }
}
