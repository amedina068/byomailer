<?php

namespace App\Http\Controllers\Settings;

use App\Actions\SaveSesCredential;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailCredentialRequest;
use App\Http\Resources\EmailCredentialResource;
use App\Models\EmailCredential;
use App\Services\AwsCredentialValidator;
use App\Services\SesClientManager;
use App\Support\AwsRoleSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EmailCredentialController extends Controller
{
    public function index(Request $request): Response
    {
        $credential = $request->user()
            ->sesCredential()
            ->first();

        return Inertia::render('settings/EmailCredentials', [
            'credential' => $credential
                ? EmailCredentialResource::make($credential)->resolve($request)
                : null,
            'roleConnection' => AwsRoleSetup::for($request->user()->client),
        ]);
    }

    /**
     * Save new AWS keys for the SES credential of the client.
     *
     * The keys must belong to the AWS account that the credential already
     * uses, because the SNS topic and the tenant exist only in that account.
     */
    public function store(
        StoreEmailCredentialRequest $request,
        AwsCredentialValidator $credentialValidator,
        SaveSesCredential $saveSesCredential,
    ): RedirectResponse {
        $data = $request->validated();
        $user = $request->user();

        $accountId = $credentialValidator->fetchAccountId($data['access_key'], $data['secret_key'], $data['region']);

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'access_key' => 'AWS did not accept these keys. Check the access key, the secret key and the region.',
            ]);
        }

        $currentAccountId = EmailCredential::query()
            ->where('client_id', $user->client_id)
            ->where('type', 'ses')
            ->value('aws_account_id');

        if ($currentAccountId !== null && $currentAccountId !== $accountId) {
            throw ValidationException::withMessages([
                'access_key' => 'These keys belong to a different AWS account. To connect a new AWS account, run the AWS setup again.',
            ]);
        }

        $saveSesCredential->handle($user, [
            'access_key' => $data['access_key'],
            'secret_key' => $data['secret_key'],
            'role_arn' => null,
            'region' => $data['region'],
            'aws_account_id' => $accountId,
        ]);

        return back()->with('success', 'Email credentials saved.');
    }

    public function refreshQuota(Request $request, SesClientManager $clientManager): JsonResponse
    {
        $user = $request->user();

        if (! $user->sesCredential) {
            return response()->json(['error' => 'No SES credentials found.'], 404);
        }

        try {
            $quota = $clientManager->fetchAndUpdateQuota($user);

            return response()->json(['success' => true, 'quota' => $quota]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to fetch quota: '.$e->getMessage()], 500);
        }
    }

    public function recheckPermissions(Request $request, SesClientManager $clientManager): JsonResponse
    {
        $user = $request->user();

        if (! $user->sesCredential) {
            return response()->json(['error' => 'No SES credentials found.'], 404);
        }

        try {
            $permissions = $clientManager->fetchAndUpdatePermissions($user);

            return response()->json(['success' => true, 'permissions' => $permissions]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to check permissions: '.$e->getMessage()], 500);
        }
    }
}
