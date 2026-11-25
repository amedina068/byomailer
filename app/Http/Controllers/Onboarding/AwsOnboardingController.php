<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\ConnectSesAccount;
use App\Exceptions\SesSetupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateAwsCredentialsRequest;
use App\Services\AwsCredentialValidator;
use App\Support\AwsRoleSetup;
use Aws\Credentials\Credentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AwsOnboardingController extends Controller
{
    public function __construct(
        private AwsCredentialValidator $credentialValidator,
        private ConnectSesAccount $connectSesAccount,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $hasCredentials = $user->sesCredential()->exists();

        return Inertia::render('onboarding/AwsCredentials', [
            'hasCredentials' => $hasCredentials,
            'roleConnection' => AwsRoleSetup::for($user->client),
        ]);
    }

    public function storeCredentials(ValidateAwsCredentialsRequest $request): JsonResponse
    {
        $accessKey = $request->validated('access_key');
        $secretKey = $request->validated('secret_key');
        $region = $request->validated('region');

        $validationResult = $this->credentialValidator->validate($accessKey, $secretKey, $region);

        if (! $validationResult['valid']) {
            return response()->json([
                'success' => false,
                'error' => $validationResult['error'],
            ], 422);
        }

        try {
            $credential = $this->connectSesAccount->handle(
                $request->user(),
                new Credentials($accessKey, $secretKey),
                $region,
                $validationResult['account_id'],
                ['access_key' => $accessKey, 'secret_key' => $secretKey, 'role_arn' => null],
            );
        } catch (SesSetupException $exception) {
            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'credential_id' => $credential->id,
        ]);
    }
}
