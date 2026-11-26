<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\ConnectSesAccount;
use App\Exceptions\AwsCredentialsException;
use App\Exceptions\SesSetupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectAwsRoleRequest;
use App\Services\AwsCredentialValidator;
use App\Services\SesCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ConnectAwsRoleController extends Controller
{
    /**
     * Connect the AWS account of the client through the IAM role of its stack.
     *
     * BYOMailer finds the role by the account ID and the role name, assumes
     * it with the external ID of the client, and checks the SES access. Then
     * it sets up the account and saves the role in place of the keys.
     */
    public function __invoke(
        ConnectAwsRoleRequest $request,
        SesCredentials $sesCredentials,
        AwsCredentialValidator $validator,
        ConnectSesAccount $connectSesAccount,
    ): RedirectResponse {
        abort_unless($sesCredentials->roleConnectionEnabled(), 404);

        $user = $request->user();
        $accountId = $request->validated('aws_account_id');
        $region = $request->validated('region');
        $roleArn = $sesCredentials->roleArnFor($accountId);

        try {
            $aws = $sesCredentials->assumeRole($roleArn, $user->client->awsExternalId(), 'byomailer-client-'.$user->client_id);

            $check = $validator->validate($aws->getAccessKeyId(), $aws->getSecretKey(), $region, $aws->getSecurityToken());

            if (! $check['valid']) {
                throw new SesSetupException($check['error'] ?? 'BYOMailer could not reach SES through the role.');
            }

            $connectSesAccount->handle($user, $aws, $region, $accountId, [
                'role_arn' => $roleArn,
                'access_key' => null,
                'secret_key' => null,
            ]);
        } catch (AwsCredentialsException|SesSetupException $exception) {
            throw ValidationException::withMessages(['aws_account_id' => $exception->getMessage()]);
        }

        return to_route('email-credentials.index')
            ->with('success', 'BYOMailer is connected to your AWS account through the IAM role.');
    }
}
