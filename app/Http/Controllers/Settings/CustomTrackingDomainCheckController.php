<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\EmailIdentity;
use App\Services\TrackingDomainVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CustomTrackingDomainCheckController extends Controller
{
    private const CHECK_COOLDOWN_SECONDS = 300;

    /**
     * @return array{verified: bool, checked_at: string, error?: string}
     */
    public function __invoke(Request $request, EmailIdentity $emailIdentity, TrackingDomainVerifier $verifier): JsonResponse
    {
        if (! $emailIdentity->custom_tracking_domain) {
            return response()->json(['error' => 'No custom tracking domain configured.'], 404);
        }

        $cacheKey = "tracking-domain-check:{$emailIdentity->id}";

        $cached = Cache::get($cacheKey);

        if ($cached) {
            $status = isset($cached['error']) ? 422 : 200;

            return response()->json($cached, $status);
        }

        $result = $verifier->verify($emailIdentity);

        if (! $result['verified'] && isset($result['error'])) {
            $response = [
                'verified' => false,
                'error' => $result['error'],
                'checked_at' => now()->toIso8601String(),
            ];

            Cache::put($cacheKey, $response, self::CHECK_COOLDOWN_SECONDS);

            return response()->json($response, 422);
        }

        $response = [
            'verified' => $result['verified'],
            'checked_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $response, self::CHECK_COOLDOWN_SECONDS);

        return response()->json($response);
    }
}
