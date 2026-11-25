<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Closure;

class AwsCredentialValidator
{
    private ?Closure $stsClientFactory = null;

    private ?Closure $sesV2ClientFactory = null;

    private ?Closure $sesV1ClientFactory = null;

    /**
     * Set a custom STS client factory for testing.
     *
     * @param  Closure(array<string, mixed>): StsClient  $factory
     */
    public function setStsClientFactory(Closure $factory): void
    {
        $this->stsClientFactory = $factory;
    }

    /**
     * Set a custom SES V2 client factory for testing.
     *
     * @param  Closure(array<string, mixed>): SesV2Client  $factory
     */
    public function setSesClientFactory(Closure $factory): void
    {
        $this->sesV2ClientFactory = $factory;
    }

    /**
     * Set a custom SES V1 client factory for testing.
     *
     * @param  Closure(array<string, mixed>): SesClient  $factory
     */
    public function setSesV1ClientFactory(Closure $factory): void
    {
        $this->sesV1ClientFactory = $factory;
    }

    /**
     * Fetch the AWS account ID for the given credentials.
     */
    public function fetchAccountId(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): ?string
    {
        try {
            $stsClient = $this->createStsClient($accessKey, $secretKey, $region, $sessionToken);
            $result = $stsClient->getCallerIdentity();

            return $result['Account'] ?? null;
        } catch (AwsException) {
            return null;
        }
    }

    /**
     * @return array{valid: bool, account_id?: string, error?: string}
     */
    public function validate(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): array
    {
        try {
            $stsClient = $this->createStsClient($accessKey, $secretKey, $region, $sessionToken);
            $result = $stsClient->getCallerIdentity();
            $accountId = $result['Account'];
        } catch (AwsException $e) {
            return [
                'valid' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }

        try {
            $sesV2Client = $this->createSesV2Client($accessKey, $secretKey, $region, $sessionToken);
            $sesV2Client->listConfigurationSets(['PageSize' => 1]);
        } catch (AwsException $e) {
            return [
                'valid' => false,
                'error' => 'SES access denied: The IAM credentials must have SES, SNS, and IAM permissions. '
                    .'Please add ses:ListConfigurationSets, ses:CreateConfigurationSet, ses:CreateConfigurationSetEventDestination, ses:UpdateConfigurationSetEventDestination, ses:PutConfigurationSetTrackingOptions, ses:DeleteConfigurationSet, '
                    .'ses:SendEmail, ses:SendBulkTemplatedEmail, ses:PutEmailIdentityConfigurationSetAttributes, '
                    .'ses:CreateTenant, ses:CreateTenantResourceAssociation, ses:GetTenant, ses:UpdateReputationEntityPolicy, ses:DeleteTenantResourceAssociation, '
                    .'sns:CreateTopic, sns:DeleteTopic, sns:Subscribe, sns:ConfirmSubscription, and iam:SimulatePrincipalPolicy permissions.',
            ];
        }

        try {
            $sesV1Client = $this->createSesV1Client($accessKey, $secretKey, $region, $sessionToken);
            $sesV1Client->listTemplates(['MaxItems' => 1]);
        } catch (AwsException $e) {
            return [
                'valid' => false,
                'error' => 'SES template access denied: The IAM credentials must have permissions to manage email templates. '
                    .'Please add ses:CreateTemplate, ses:DeleteTemplate, and ses:ListTemplates permissions.',
            ];
        }

        return [
            'valid' => true,
            'account_id' => $accountId,
        ];
    }

    private function createStsClient(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): StsClient
    {
        $config = [
            'version' => '2011-06-15',
            'region' => $region,
            'credentials' => array_filter([
                'key' => $accessKey,
                'secret' => $secretKey,
                'token' => $sessionToken,
            ]),
        ];

        if ($this->stsClientFactory) {
            return ($this->stsClientFactory)($config);
        }

        return new StsClient($config);
    }

    private function createSesV2Client(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): SesV2Client
    {
        $config = [
            'version' => '2019-09-27',
            'region' => $region,
            'credentials' => array_filter([
                'key' => $accessKey,
                'secret' => $secretKey,
                'token' => $sessionToken,
            ]),
        ];

        if ($this->sesV2ClientFactory) {
            return ($this->sesV2ClientFactory)($config);
        }

        return new SesV2Client($config);
    }

    private function createSesV1Client(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): SesClient
    {
        $config = [
            'version' => '2010-12-01',
            'region' => $region,
            'credentials' => array_filter([
                'key' => $accessKey,
                'secret' => $secretKey,
                'token' => $sessionToken,
            ]),
        ];

        if ($this->sesV1ClientFactory) {
            return ($this->sesV1ClientFactory)($config);
        }

        return new SesClient($config);
    }
}
