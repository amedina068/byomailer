<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\EmailIdentity;
use App\Services\LinkDomainVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CustomLinkDomainCheckController extends Controller
{
    private const CHECK_COOLDOWN_SECONDS = 300;

    private const PENDING_COOLDOWN_SECONDS = 30;

    public function __invoke(Request $request, EmailIdentity $emailIdentity, LinkDomainVerifier $verifier): JsonResponse
    {
        if (! $emailIdentity->custom_link_domain) {
            return response()->json(['error' => 'No custom link domain configured.'], 404);
        }

        $cacheKey = "link-domain-check:{$emailIdentity->id}";

        $cached = Cache::get($cacheKey);

        if ($cached) {
            return response()->json($cached, isset($cached['error']) ? 422 : 200);
        }

        $result = $verifier->verify($emailIdentity);

        if ($result['verified']) {
            Cache::forget("link-domain-host:{$emailIdentity->custom_link_domain}");
        }

        if (! $result['verified'] && isset($result['error'])) {
            $response = [
                'verified' => false,
                'error' => $result['error'],
                'checked_at' => now()->toIso8601String(),
            ];

            Cache::put($cacheKey, $response, self::PENDING_COOLDOWN_SECONDS);

            return response()->json($response, 422);
        }

        $response = [
            'verified' => $result['verified'],
            'checked_at' => now()->toIso8601String(),
        ];

        $cooldown = $result['verified']
            ? self::CHECK_COOLDOWN_SECONDS
            : self::PENDING_COOLDOWN_SECONDS;

        Cache::put($cacheKey, $response, $cooldown);

        return response()->json($response);
    }
}
