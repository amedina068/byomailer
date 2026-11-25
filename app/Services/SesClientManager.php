<?php

namespace App\Services;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\Ses\SesLogger;
use Aws\Iam\IamClient;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Illuminate\Contracts\Config\Repository;

class SesClientManager
{
    /**
     * @var array<string, SesClient>
     */
    private array $clients = [];

    /**
     * @var array<string, SesV2Client>
     */
    private array $v2Clients = [];

    public function __construct(private Repository $config, private SesCredentials $credentials) {}

    public function clientFor(User $user): SesClient
    {
        $cacheKey = 'user-'.$user->id;

        if (! array_key_exists($cacheKey, $this->clients)) {
            $client = new SesClient($this->clientConfig($user));
            $client->getHandlerList()->appendInit(new SesLogger($user->client_id));
            $this->clients[$cacheKey] = $client;
        }

        return $this->clients[$cacheKey];
    }

    public function v2ClientFor(User $user): SesV2Client
    {
        $cacheKey = 'user-'.$user->id;

        if (! array_key_exists($cacheKey, $this->v2Clients)) {
            $client = new SesV2Client($this->v2ClientConfig($user));
            $client->getHandlerList()->appendInit(new SesLogger($user->client_id));
            $this->v2Clients[$cacheKey] = $client;
        }

        return $this->v2Clients[$cacheKey];
    }

    /**
     * The SES mail transport of Laravel reads only a key, a secret and a
     * token. So a role gives its temporary credentials here. One mail ends
     * long before the credentials expire.
     *
     * @return array{
     *     transport: string,
     *     key: string|null,
     *     secret: string|null,
     *     token?: string,
     *     region: string|null
     * }
     */
    public function mailerConfigFor(User $user): array
    {
        $credential = $this->credentialForUser($user);

        if (! $credential->usesRole()) {
            return [
                'transport' => 'ses',
                'key' => $credential->access_key,
                'secret' => $credential->secret_key,
                'region' => $credential->region,
            ];
        }

        $credentials = $this->credentials->for($credential);

        return [
            'transport' => 'ses',
            'key' => $credentials->getAccessKeyId(),
            'secret' => $credentials->getSecretKey(),
            'token' => (string) $credentials->getSecurityToken(),
            'region' => $credential->region,
        ];
    }

    /**
     * Fetch account quota information from SES and update the credential.
     *
     * @return array{
     *     production_access_enabled: bool,
     *     sending_enabled: bool,
     *     enforcement_status: string|null,
     *     max_24_hour_send: int,
     *     max_send_rate: int,
     *     sent_last_24_hours: int
     * }
     */
    public function fetchAndUpdateQuota(User $user): array
    {
        $client = $this->v2ClientFor($user);
        $response = $client->getAccount();

        $quota = [
            'production_access_enabled' => $response['ProductionAccessEnabled'] ?? false,
            'sending_enabled' => $response['SendingEnabled'] ?? false,
            'enforcement_status' => $response['EnforcementStatus'] ?? null,
            'max_24_hour_send' => (int) ($response['SendQuota']['Max24HourSend'] ?? 200),
            'max_send_rate' => (int) ($response['SendQuota']['MaxSendRate'] ?? 1),
            'sent_last_24_hours' => (int) ($response['SendQuota']['SentLast24Hours'] ?? 0),
        ];

        $credential = $this->credentialForUser($user);
        $credential->update([
            ...$quota,
            'quota_fetched_at' => now(),
        ]);

        return $quota;
    }

    /**
     * Check SES API permissions using IAM policy simulation.
     *
     * Requires the IAM user to have `iam:SimulatePrincipalPolicy` and `sts:GetCallerIdentity` permissions.
     *
     * @return array{
     *     can_create_template: bool,
     *     can_delete_template: bool,
     *     can_send_bulk_email: bool,
     *     can_send_email: bool
     * }
     */
    public function fetchAndUpdatePermissions(User $user): array
    {
        $credential = $this->credentialForUser($user);

        if ($credential->usesRole()) {
            return $this->grantRolePermissions($credential);
        }

        $credentialsArray = [
            'key' => $credential->access_key,
            'secret' => $credential->secret_key,
        ];

        $callerArn = $this->getCallerArn($credentialsArray, $credential->region);

        $actionMap = [
            'can_create_template' => 'ses:CreateEmailTemplate',
            'can_delete_template' => 'ses:DeleteEmailTemplate',
            'can_send_bulk_email' => 'ses:SendBulkEmail',
            'can_send_email' => 'ses:SendEmail',
        ];

        $results = $this->simulatePermissions($credentialsArray, $credential->region, $callerArn, array_values($actionMap));

        $permissions = [];
        foreach ($actionMap as $key => $action) {
            $permissions[$key] = $results[$action] ?? false;
        }

        $credential->update([
            ...$permissions,
            'permissions_checked_at' => now(),
        ]);

        return $permissions;
    }

    /**
     * A role session cannot call `iam:SimulatePrincipalPolicy`. The policy of
     * the CloudFormation stack gives the 4 actions, so a role that BYOMailer
     * can assume has them.
     *
     * @return array{
     *     can_create_template: bool,
     *     can_delete_template: bool,
     *     can_send_bulk_email: bool,
     *     can_send_email: bool
     * }
     */
    private function grantRolePermissions(EmailCredential $credential): array
    {
        $this->credentials->for($credential);

        $permissions = [
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
        ];

        $credential->update([
            ...$permissions,
            'permissions_checked_at' => now(),
        ]);

        return $permissions;
    }

    /**
     * @param  array{key: string, secret: string}  $credentials
     */
    private function getCallerArn(array $credentials, string $region): string
    {
        $stsClient = new StsClient([
            'version' => '2011-06-15',
            'region' => $region,
            'credentials' => $credentials,
        ]);

        $result = $stsClient->getCallerIdentity();

        return $result['Arn'];
    }

    /**
     * @param  array{key: string, secret: string}  $credentials
     * @param  array<int, string>  $actions
     * @return array<string, bool>
     */
    private function simulatePermissions(array $credentials, string $region, string $callerArn, array $actions): array
    {
        $iamClient = new IamClient([
            'version' => '2010-05-08',
            'region' => $region,
            'credentials' => $credentials,
        ]);

        $response = $iamClient->simulatePrincipalPolicy([
            'PolicySourceArn' => $callerArn,
            'ActionNames' => $actions,
        ]);

        $results = [];
        foreach ($response['EvaluationResults'] as $evaluation) {
            $results[$evaluation['EvalActionName']] = $evaluation['EvalDecision'] === 'allowed';
        }

        return $results;
    }

    /**
     * Get the max send rate for a user (emails per second). A user without
     * SES credentials gets 1, so the sequence cron and the send limiter do
     * not crash on that user.
     */
    public function getMaxSendRateFor(User $user): int
    {
        $credential = $user->relationLoaded('sesCredential')
            ? $user->sesCredential
            : $user->sesCredential()->first();

        return $credential?->max_send_rate ?? 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(User $user): array
    {
        $credential = $this->credentialForUser($user);

        return [
            'version' => '2010-12-01',
            'region' => $credential->region,
            'credentials' => $this->credentials->clientCredentials($credential),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v2ClientConfig(User $user): array
    {
        $credential = $this->credentialForUser($user);

        return [
            'version' => '2019-09-27',
            'region' => $credential->region,
            'credentials' => $this->credentials->clientCredentials($credential),
        ];
    }

    private function credentialForUser(User $user): EmailCredential
    {
        if ($user->relationLoaded('sesCredential')) {
            return $user->sesCredential;
        }

        return $user->sesCredential()->first();
    }
}
