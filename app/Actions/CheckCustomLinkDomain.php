<?php

namespace App\Actions;

use App\Models\EmailIdentity;
use App\Services\LinkDomainVerifier;
use Illuminate\Support\Facades\Cache;

class CheckCustomLinkDomain
{
    private const CHECK_COOLDOWN_SECONDS = 300;

    private const PENDING_COOLDOWN_SECONDS = 30;

    public function __construct(private LinkDomainVerifier $verifier) {}

    /**
     * Ask Cloudflare if the custom link domain of the identity is live, and
     * mark it verified when it is. A result stays in the cache for a short
     * time, so a repeated check does not call Cloudflare again. The identity
     * must have a custom link domain.
     *
     * @return array{verified: bool, checked_at: string, error?: string}
     */
    public function handle(EmailIdentity $identity): array
    {
        $cacheKey = "link-domain-check:{$identity->id}";

        $cached = Cache::get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $result = $this->verifier->verify($identity);

        if ($result['verified']) {
            Cache::forget("link-domain-host:{$identity->custom_link_domain}");
        }

        $response = [
            'verified' => $result['verified'],
            'checked_at' => now()->toIso8601String(),
        ];

        if (! $result['verified'] && isset($result['error'])) {
            $response['error'] = $result['error'];
        }

        $cooldown = $result['verified']
            ? self::CHECK_COOLDOWN_SECONDS
            : self::PENDING_COOLDOWN_SECONDS;

        Cache::put($cacheKey, $response, $cooldown);

        return $response;
    }
}
