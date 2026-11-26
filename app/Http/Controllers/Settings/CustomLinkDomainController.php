<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateCustomLinkDomain;
use App\Exceptions\CloudflareException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomLinkDomainRequest;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use Illuminate\Http\JsonResponse;

class CustomLinkDomainController extends Controller
{
    public function update(
        UpdateCustomLinkDomainRequest $request,
        EmailIdentity $emailIdentity,
        CloudflareClient $cloudflare,
        UpdateCustomLinkDomain $updateCustomLinkDomain,
    ): JsonResponse {
        if (! $cloudflare->isConfigured()) {
            return response()->json(['error' => 'Custom link domains are not available.'], 503);
        }

        $enabled = $request->boolean('enabled');

        try {
            $updateCustomLinkDomain->handle(
                $emailIdentity,
                $enabled ? $request->validated('custom_link_domain') : null,
            );
        } catch (CloudflareException $e) {
            return response()->json([
                'error' => 'Failed to register link domain with Cloudflare: '.$e->getMessage(),
            ], 422);
        }

        if (! $enabled) {
            return response()->json(['success' => true]);
        }

        return response()->json([
            'success' => true,
            'cname_target' => config('services.cloudflare.cname_target'),
        ]);
    }
}
