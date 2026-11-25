<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\EmailIdentityProvisioningException;
use App\Http\Controllers\Controller;
use App\Models\EmailIdentity;
use App\Services\EmailIdentityProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailIdentityRefreshController extends Controller
{
    public function __construct(
        private EmailIdentityProvisioningService $provisioningService,
    ) {}

    public function __invoke(Request $request, EmailIdentity $emailIdentity): JsonResponse
    {
        try {
            $this->provisioningService->refreshStatus($request->user(), $emailIdentity);
        } catch (EmailIdentityProvisioningException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->reason === EmailIdentityProvisioningException::REASON_NO_CREDENTIAL
                    ? 'No AWS credentials found.'
                    : $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'identity_status' => $emailIdentity->identity_status->value,
            'dkim_status' => $emailIdentity->dkim_status->value,
            'mail_from_status' => $emailIdentity->mail_from_status->value,
        ]);
    }
}
