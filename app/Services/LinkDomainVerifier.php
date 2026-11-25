<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use App\Models\EmailIdentity;
use Illuminate\Support\Facades\Cache;

/**
 * Confirms a customer's custom link domain is live on Cloudflare for SaaS
 * (hostname proxied + TLS certificate issued), then confirms that a request
 * through the domain reaches the app, and marks it verified.
 */
class LinkDomainVerifier
{
    public function __construct(
        private CloudflareClient $cloudflare,
        private LinkDomainProbe $probe,
    ) {}

    /**
     * @return array{verified: bool, error?: string}
     */
    public function verify(EmailIdentity $identity): array
    {
        if (! $identity->custom_link_domain) {
            return ['verified' => false, 'error' => 'No custom link domain configured.'];
        }

        if ($identity->custom_link_domain_verified_at) {
            return ['verified' => true];
        }

        if (! $identity->cf_custom_hostname_id) {
            return ['verified' => false, 'error' => 'No Cloudflare hostname registered.'];
        }

        try {
            $hostname = $this->cloudflare->getCustomHostname($identity->cf_custom_hostname_id);
        } catch (CloudflareException $e) {
            return ['verified' => false, 'error' => $e->getMessage()];
        }

        if ($hostname['status'] !== 'active' || $hostname['ssl_status'] !== 'active') {
            return ['verified' => false];
        }

        $probe = $this->probe->check($identity->custom_link_domain);

        if (! $probe['healthy']) {
            return ['verified' => false, 'error' => $probe['reason'] ?? 'The domain does not reach the app.'];
        }

        return Cache::lock("link-domain-activate:{$identity->id}", 30)->get(
            fn () => $this->activate($identity->fresh())
        ) ?: ['verified' => false, 'error' => 'Could not acquire lock. Another verification may be in progress.'];
    }

    /**
     * @return array{verified: bool}
     */
    private function activate(EmailIdentity $identity): array
    {
        if (! $identity->custom_link_domain_verified_at) {
            $identity->update(['custom_link_domain_verified_at' => now()]);
        }

        return ['verified' => true];
    }
}
