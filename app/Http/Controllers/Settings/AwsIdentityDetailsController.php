<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DkimStatus;
use App\Http\Controllers\Controller;
use App\Services\SesCredentials;
use App\Services\SesDomainIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AwsIdentityDetailsController extends Controller
{
    public function __construct(
        private SesDomainIdentityService $domainIdentityService,
        private SesCredentials $sesCredentials,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string'],
        ]);

        $credential = $request->user()->sesCredential;

        if (! $credential) {
            return response()->json([
                'success' => false,
                'error' => 'No AWS credentials found.',
            ], 422);
        }

        $aws = $this->sesCredentials->for($credential);

        $result = $this->domainIdentityService->getIdentityStatus(
            $validated['domain'],
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken(),
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Unable to load identity details.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'mail_from_domain' => $result['mail_from_domain'] ?? null,
            'verification_status' => $result['status'] ?? 'pending',
            'dkim_status' => $result['dkim_status'] ?? DkimStatus::Pending->value,
        ]);
    }
}
