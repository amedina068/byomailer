<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\EmailIdentityProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTrackingSettingsRequest;
use App\Models\EmailIdentity;
use App\Services\EmailIdentityProvisioningService;
use Illuminate\Http\JsonResponse;

class EmailIdentityTrackingController extends Controller
{
    public function __invoke(
        UpdateTrackingSettingsRequest $request,
        EmailIdentity $emailIdentity,
        EmailIdentityProvisioningService $provisioningService
    ): JsonResponse {
        try {
            $provisioningService->updateTracking(
                $request->user(),
                $emailIdentity,
                $request->boolean('open_tracking_enabled'),
                $request->boolean('click_tracking_enabled'),
            );
        } catch (EmailIdentityProvisioningException $e) {
            return match ($e->reason) {
                EmailIdentityProvisioningException::REASON_NO_CREDENTIAL => response()->json(['error' => 'No AWS credentials found. Add your AWS credentials in Settings.'], 404),
                EmailIdentityProvisioningException::REASON_NO_CONFIGURATION_SET => response()->json(['error' => $e->getMessage()], 404),
                default => response()->json(['error' => $e->getMessage()], 422),
            };
        }

        return response()->json(['success' => true]);
    }
}
