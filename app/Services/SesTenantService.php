<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Closure;

class SesTenantService
{
    private ?Closure $clientFactory = null;

    /**
     * Set a custom client factory for testing.
     *
     * @param  Closure(array<string, mixed>): SesV2Client  $factory
     */
    public function setClientFactory(Closure $factory): void
    {
        $this->clientFactory = $factory;
    }

    /**
     * Provision a tenant: create it and set the reputation policy.
     *
     * @return array{success: bool, error?: string}
     */
    public function provisionTenant(
        string $tenantName,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        $result = $this->createTenant($tenantName, $accessKey, $secretKey, $region);

        if (! $result['success']) {
            return $result;
        }

        return $this->updateReputationPolicy($result['tenant_arn'], $region, 'standard', $accessKey, $secretKey);
    }

    /**
     * Associate an identity and its configuration set with a tenant.
     *
     * The configuration set is intentionally NOT set as the identity's default.
     * It is applied per-send via the ConfigurationSetName parameter / X-SES-CONFIGURATION-SET header.
     *
     * @return array{success: bool, error?: string}
     */
    public function associateIdentityWithTenant(
        string $tenantName,
        string $region,
        string $accountId,
        string $domain,
        string $configSetName,
        string $accessKey,
        string $secretKey,
    ): array {
        $identityArn = self::buildIdentityArn($region, $accountId, $domain);
        $identityResult = $this->associateResource($tenantName, $identityArn, $accessKey, $secretKey, $region);

        if (! $identityResult['success']) {
            return $identityResult;
        }

        $configSetArn = self::buildConfigurationSetArn($region, $accountId, $configSetName);

        return $this->associateResource($tenantName, $configSetArn, $accessKey, $secretKey, $region);
    }

    /**
     * Create an SES tenant.
     *
     * @return array{success: bool, tenant_arn?: string, error?: string}
     */
    public function createTenant(
        string $tenantName,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $response = $client->createTenant([
                'TenantName' => $tenantName,
            ]);

            return ['success' => true, 'tenant_arn' => $response['TenantArn']];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AlreadyExistsException') {
                $tenant = $this->getTenant($tenantName, $accessKey, $secretKey, $region);

                return [
                    'success' => true,
                    'tenant_arn' => $tenant['tenant']['Tenant']['TenantArn'] ?? null,
                ];
            }

            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Associate a resource (identity or configuration set) with a tenant.
     *
     * @return array{success: bool, error?: string}
     */
    public function associateResource(
        string $tenantName,
        string $resourceArn,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $client->createTenantResourceAssociation([
                'TenantName' => $tenantName,
                'ResourceArn' => $resourceArn,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AlreadyExistsException') {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Update the reputation policy for a tenant.
     *
     * @param  string  $policy  One of 'standard', 'strict', or 'none'
     * @return array{success: bool, error?: string}
     */
    public function updateReputationPolicy(
        string $tenantArn,
        string $region,
        string $policy,
        string $accessKey,
        string $secretKey,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $policyArn = "arn:aws:ses:{$region}:aws:reputation-policy/{$policy}";

            $client->updateReputationEntityPolicy([
                'ReputationEntityType' => 'RESOURCE',
                'ReputationEntityReference' => $tenantArn,
                'ReputationEntityPolicy' => $policyArn,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Get tenant details including reputation metrics.
     *
     * @return array{success: bool, tenant?: array<string, mixed>, error?: string}
     */
    public function getTenant(
        string $tenantName,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $response = $client->getTenant([
                'TenantName' => $tenantName,
            ]);

            return [
                'success' => true,
                'tenant' => $response->toArray(),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a tenant.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteTenant(
        string $tenantName,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $client->deleteTenant([
                'TenantName' => $tenantName,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a resource association from a tenant.
     *
     * @return array{success: bool, error?: string}
     */
    public function dissociateResource(
        string $tenantName,
        string $resourceArn,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $client->deleteTenantResourceAssociation([
                'TenantName' => $tenantName,
                'ResourceArn' => $resourceArn,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Build an ARN for an SES email identity.
     */
    public static function buildIdentityArn(string $region, string $accountId, string $domain): string
    {
        return "arn:aws:ses:{$region}:{$accountId}:identity/{$domain}";
    }

    /**
     * Build an ARN for an SES configuration set.
     */
    public static function buildConfigurationSetArn(string $region, string $accountId, string $configSetName): string
    {
        return "arn:aws:ses:{$region}:{$accountId}:configuration-set/{$configSetName}";
    }

    /**
     * Build an ARN for an SES email template.
     */
    public static function buildTemplateArn(string $region, string $accountId, string $templateName): string
    {
        return "arn:aws:ses:{$region}:{$accountId}:template/{$templateName}";
    }

    private function createClient(string $accessKey, string $secretKey, string $region): SesV2Client
    {
        $config = [
            'version' => '2019-09-27',
            'region' => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ];

        if ($this->clientFactory) {
            return ($this->clientFactory)($config);
        }

        return new SesV2Client($config);
    }
}
