<?php

namespace App\Actions;

use App\Exceptions\CloudflareException;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateCustomLinkDomain
{
    public function __construct(private CloudflareClient $cloudflare) {}

    /**
     * Set the custom link domain of a sender identity, or clear it when the
     * domain is null. The settings page and the MCP tool use this write.
     *
     * @throws CloudflareException when Cloudflare does not register the new domain.
     */
    public function handle(EmailIdentity $identity, ?string $domain): void
    {
        if ($domain === null) {
            $this->replace($identity, null, null);

            return;
        }

        if ($identity->custom_link_domain === $domain && $identity->cf_custom_hostname_id) {
            return;
        }

        // Register the new hostname first. If Cloudflare rejects it, no state
        // changes and the previous link domain continues to work.
        $hostname = $this->cloudflare->createCustomHostname($domain);

        $this->replace($identity, $domain, $hostname['id']);
    }

    private function replace(EmailIdentity $identity, ?string $domain, ?string $hostnameId): void
    {
        $previousDomain = $identity->custom_link_domain;
        $previousHostnameId = $identity->cf_custom_hostname_id;

        $identity->update([
            'custom_link_domain' => $domain,
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => $hostnameId,
        ]);

        $this->deleteHostnameQuietly($previousHostnameId);
        $this->forgetCaches($identity, $previousDomain);
    }

    private function deleteHostnameQuietly(?string $hostnameId): void
    {
        if (! $hostnameId) {
            return;
        }

        try {
            $this->cloudflare->deleteCustomHostname($hostnameId);
        } catch (CloudflareException $e) {
            Log::warning('Failed to delete Cloudflare custom hostname', [
                'hostname_id' => $hostnameId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function forgetCaches(EmailIdentity $identity, ?string $previousDomain): void
    {
        Cache::forget("link-domain-check:{$identity->id}");

        foreach (array_filter([$previousDomain, $identity->custom_link_domain]) as $host) {
            Cache::forget("link-domain-host:{$host}");
        }
    }
}
