<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\SesCredentials;
use App\Services\SesDomainIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AwsIdentityListController extends Controller
{
    public function __construct(
        private SesDomainIdentityService $domainIdentityService,
        private SesCredentials $sesCredentials,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $credential = $user->sesCredential;

        if (! $credential) {
            return response()->json([
                'success' => false,
                'error' => 'No AWS credentials found. Complete the AWS onboarding first.',
            ], 422);
        }

        $aws = $this->sesCredentials->for($credential);

        $result = $this->domainIdentityService->listDomainIdentities(
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken(),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to list identities from AWS.',
            ], 422);
        }

        $existingDomains = $user->client
            ->emailIdentities()
            ->whereNotNull('domain')
            ->pluck('domain')
            ->unique()
            ->values()
            ->all();

        $identities = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'sending_enabled' => $row['sending_enabled'],
            'verification_status' => $row['verification_status'],
            'already_added' => in_array($row['name'], $existingDomains, true),
        ], $result['identities'] ?? []);

        return response()->json([
            'success' => true,
            'identities' => $identities,
        ]);
    }
}
