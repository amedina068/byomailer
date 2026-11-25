<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\CloudflareException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomLinkDomainRequest;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CustomLinkDomainController extends Controller
{
    public function update(
        UpdateCustomLinkDomainRequest $request,
        EmailIdentity $emailIdentity,
        CloudflareClient $cloudflare
    ): JsonResponse {
        if (! $cloudflare->isConfigured()) {
            return response()->json(['error' => 'Custom link domains are not available.'], 503);
        }

        if ($request->boolean('enabled')) {
            return $this->enable($request, $emailIdentity, $cloudflare);
        }

        return $this->disable($emailIdentity, $cloudflare);
    }

    private function enable(
        UpdateCustomLinkDomainRequest $request,
        EmailIdentity $emailIdentity,
        CloudflareClient $cloudflare
    ): JsonResponse {
        $domain = $request->validated('custom_link_domain');

        if ($emailIdentity->custom_link_domain === $domain && $emailIdentity->cf_custom_hostname_id) {
            return response()->json([
                'success' => true,
                'cname_target' => config('services.cloudflare.cname_target'),
            ]);
        }

        // Register the new hostname first: if Cloudflare rejects it, no state
        // changes and the previous link domain (if any) keeps working.
        try {
            $hostname = $cloudflare->createCustomHostname($domain);
        } catch (CloudflareException $e) {
            return response()->json([
                'error' => 'Failed to register link domain with Cloudflare: '.$e->getMessage(),
            ], 422);
        }

        $previousDomain = $emailIdentity->custom_link_domain;
        $previousHostnameId = $emailIdentity->cf_custom_hostname_id;

        $emailIdentity->update([
            'custom_link_domain' => $domain,
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => $hostname['id'],
        ]);

        $this->deleteHostnameQuietly($cloudflare, $previousHostnameId);
        $this->forgetCaches($emailIdentity, $previousDomain);

        return response()->json([
            'success' => true,
            'cname_target' => config('services.cloudflare.cname_target'),
        ]);
    }

    private function disable(EmailIdentity $emailIdentity, CloudflareClient $cloudflare): JsonResponse
    {
        $previousDomain = $emailIdentity->custom_link_domain;
        $previousHostnameId = $emailIdentity->cf_custom_hostname_id;

        $emailIdentity->update([
            'custom_link_domain' => null,
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => null,
        ]);

        $this->deleteHostnameQuietly($cloudflare, $previousHostnameId);
        $this->forgetCaches($emailIdentity, $previousDomain);

        return response()->json(['success' => true]);
    }

    private function deleteHostnameQuietly(CloudflareClient $cloudflare, ?string $hostnameId): void
    {
        if (! $hostnameId) {
            return;
        }

        try {
            $cloudflare->deleteCustomHostname($hostnameId);
        } catch (CloudflareException $e) {
            Log::warning('Failed to delete Cloudflare custom hostname', [
                'hostname_id' => $hostnameId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function forgetCaches(EmailIdentity $emailIdentity, ?string $previousDomain): void
    {
        Cache::forget("link-domain-check:{$emailIdentity->id}");

        foreach (array_filter([$previousDomain, $emailIdentity->custom_link_domain]) as $host) {
            Cache::forget("link-domain-host:{$host}");
        }
    }
}
