<?php

namespace App\Services;

use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Exceptions\AwsCredentialsException;
use App\Exceptions\EmailIdentityProvisioningException;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use Aws\Credentials\Credentials;
use Illuminate\Support\Str;

/**
 * Owns the SES side effects behind an email identity: create the domain
 * identity, its MAIL FROM domain, its configuration set and its tenant link,
 * refresh its verification status, and toggle tracking. The web controllers
 * and the MCP tools both call this class so the AWS workflow lives in one place.
 */
class EmailIdentityProvisioningService
{
    public function __construct(
        private SesDomainIdentityService $domainIdentityService,
        private SesConfigurationSetService $configurationSetService,
        private SesTenantService $tenantService,
        private EmailIdentityStatusSyncService $statusSyncService,
        private SesCredentials $sesCredentials,
    ) {}

    /**
     * Create (or import from AWS) a sender identity for the user and persist
     * its DNS records.
     *
     * @param  array{email: string, name?: string|null, existing_aws_identity?: bool, open_tracking_enabled?: bool, click_tracking_enabled?: bool, mail_from_subdomain?: string|null}  $input
     *
     * @throws EmailIdentityProvisioningException
     */
    public function provision(User $user, array $input): EmailIdentity
    {
        $credential = $this->credentialFor($user);
        $aws = $this->awsCredentialsFor($credential);

        $email = $input['email'];
        $name = $input['name'] ?? null;
        $isExistingAws = (bool) ($input['existing_aws_identity'] ?? false);
        $openTracking = (bool) ($input['open_tracking_enabled'] ?? true);
        $clickTracking = (bool) ($input['click_tracking_enabled'] ?? true);

        [, $originalDomain] = explode('@', $email);
        $requestedMailFromSubdomain = $input['mail_from_subdomain'] ?? null;
        $mailFromSubdomain = is_string($requestedMailFromSubdomain) && $requestedMailFromSubdomain !== ''
            ? strtolower(trim($requestedMailFromSubdomain))
            : 'mas.'.$originalDomain;

        $dkimTokens = [];
        $dkimSigningHostedZone = null;
        $awsStatusForApply = null;

        if ($isExistingAws) {
            $awsStatusForApply = $this->domainIdentityService->getIdentityStatus($originalDomain, $aws->getAccessKeyId(), $aws->getSecretKey(), $credential->region, $aws->getSecurityToken());

            if (! ($awsStatusForApply['success'] ?? false)) {
                throw EmailIdentityProvisioningException::awsFailed(
                    $awsStatusForApply['error'] ?? 'Could not find the identity at AWS. Refresh the list and try again.',
                );
            }

            $dkimTokens = $awsStatusForApply['dkim_tokens'] ?? [];
            $dkimSigningHostedZone = $awsStatusForApply['dkim_signing_hosted_zone'] ?? null;

            if (! empty($awsStatusForApply['mail_from_domain'])) {
                $mailFromSubdomain = $awsStatusForApply['mail_from_domain'];
            } else {
                $this->setMailFromDomain($originalDomain, $mailFromSubdomain, $credential);
            }
        } else {
            $createResult = $this->domainIdentityService->createDomainIdentity($originalDomain, $aws->getAccessKeyId(), $aws->getSecretKey(), $credential->region, $aws->getSecurityToken());

            if (! $createResult['success']) {
                throw EmailIdentityProvisioningException::awsFailed($createResult['error'] ?? 'Failed to create domain identity.');
            }

            $dkimTokens = $createResult['dkim_tokens'] ?? [];
            $dkimSigningHostedZone = $createResult['dkim_signing_hosted_zone'] ?? null;

            $this->setMailFromDomain($originalDomain, $mailFromSubdomain, $credential);
        }

        $identity = EmailIdentity::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'email' => $email,
            ],
            [
                'name' => $name,
                'original_email' => $email,
                'domain' => $originalDomain,
                'mail_from_subdomain' => $mailFromSubdomain,
                'region' => $credential->region,
                'identity_status' => $isExistingAws ? IdentityStatus::Verifying : IdentityStatus::Pending,
            ]
        );

        $this->createConfigurationSetForIdentity($identity, $credential, $openTracking, $clickTracking);
        $this->associateIdentityWithTenant($identity, $credential);

        $this->persistDnsRecords($identity, $this->buildDnsRecords(
            $originalDomain,
            $dkimTokens,
            $dkimSigningHostedZone,
            $mailFromSubdomain,
            $credential->region
        ));

        if ($isExistingAws && $awsStatusForApply !== null) {
            $this->statusSyncService->applyAwsStatus($identity, $awsStatusForApply);
        }

        return $identity->load('dnsRecords');
    }

    /**
     * Ask AWS for the current verification state and sync it onto the identity.
     *
     * @throws EmailIdentityProvisioningException
     */
    public function refreshStatus(User $user, EmailIdentity $identity): EmailIdentity
    {
        $credential = $this->credentialFor($user);
        $aws = $this->awsCredentialsFor($credential);

        $result = $this->domainIdentityService->getIdentityStatus(
            $identity->domain,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken(),
        );

        if (! ($result['success'] ?? false)) {
            throw EmailIdentityProvisioningException::awsFailed($result['error'] ?? 'Failed to check identity status.');
        }

        $this->statusSyncService->applyAwsStatus($identity, $result);

        return $identity;
    }

    /**
     * Toggle open and click tracking on the identity's configuration set.
     *
     * @throws EmailIdentityProvisioningException
     */
    public function updateTracking(User $user, EmailIdentity $identity, bool $openTracking, bool $clickTracking): EmailIdentity
    {
        $credential = $this->credentialFor($user);

        if (! $identity->configuration_set_name) {
            throw EmailIdentityProvisioningException::noConfigurationSet();
        }

        $aws = $this->awsCredentialsFor($credential);
        $eventTypes = $this->configurationSetService->getEventTypesForTracking($openTracking, $clickTracking);
        $eventResult = $this->configurationSetService->updateSnsEventDestination(
            $identity->configuration_set_name,
            'sns-webhook',
            $credential->sns_topic_arn,
            $eventTypes,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken()
        );

        if (! $eventResult['success']) {
            throw EmailIdentityProvisioningException::awsFailed('Failed to update event destination: '.$eventResult['error']);
        }

        $identity->update([
            'open_tracking_enabled' => $openTracking,
            'click_tracking_enabled' => $clickTracking,
        ]);

        return $identity;
    }

    /**
     * @throws EmailIdentityProvisioningException
     */
    private function credentialFor(User $user): EmailCredential
    {
        $credential = $user->sesCredential;

        if (! $credential) {
            throw EmailIdentityProvisioningException::noCredential();
        }

        return $credential;
    }

    /**
     * @throws EmailIdentityProvisioningException
     */
    private function awsCredentialsFor(EmailCredential $credential): Credentials
    {
        try {
            return $this->sesCredentials->for($credential);
        } catch (AwsCredentialsException $exception) {
            throw EmailIdentityProvisioningException::awsFailed($exception->getMessage());
        }
    }

    /**
     * @throws EmailIdentityProvisioningException
     */
    private function setMailFromDomain(string $domain, string $mailFromSubdomain, EmailCredential $credential): void
    {
        $aws = $this->awsCredentialsFor($credential);

        $result = $this->domainIdentityService->setMailFromDomain(
            $domain,
            $mailFromSubdomain,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken()
        );

        if (! $result['success']) {
            throw EmailIdentityProvisioningException::awsFailed($result['error'] ?? 'Failed to set MAIL FROM domain.');
        }
    }

    /**
     * @param  array<int, array{type: string, name: string, value: string, priority?: int|null, purpose?: DnsRecordPurpose|null}>  $records
     */
    private function persistDnsRecords(EmailIdentity $identity, array $records): void
    {
        $identity->dnsRecords()->delete();

        if (empty($records)) {
            return;
        }

        $identity->dnsRecords()->createMany(
            array_map(fn (array $record) => [
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'priority' => $record['priority'] ?? null,
                'purpose' => $record['purpose'] ?? null,
            ], $records)
        );
    }

    /**
     * @param  array<int, string>  $dkimTokens
     * @return array<int, array{type: string, name: string, value: string, priority?: int|null, purpose: DnsRecordPurpose}>
     */
    private function buildDnsRecords(string $domain, array $dkimTokens, ?string $dkimSigningHostedZone, ?string $mailFromSubdomain, ?string $region): array
    {
        if (empty($domain)) {
            return [];
        }

        $records = [];

        if (! empty($dkimTokens)) {
            $records = array_map(
                fn (array $record) => [...$record, 'purpose' => DnsRecordPurpose::Dkim],
                $this->domainIdentityService->getDkimRecords($domain, $dkimTokens, $dkimSigningHostedZone, $region)
            );
        }

        if ($mailFromSubdomain && $region) {
            $mailFromRecords = array_map(
                fn (array $record) => [...$record, 'purpose' => DnsRecordPurpose::MailFrom],
                $this->domainIdentityService->getMailFromRecords($mailFromSubdomain, $region)
            );
            $records = array_merge($records, $mailFromRecords);
        }

        $dmarcRecord = $this->domainIdentityService->getDmarcRecord($domain);
        $records[] = [...$dmarcRecord, 'purpose' => DnsRecordPurpose::Dmarc];

        return $records;
    }

    /**
     * @throws EmailIdentityProvisioningException
     */
    private function createConfigurationSetForIdentity(
        EmailIdentity $identity,
        EmailCredential $credential,
        bool $openTracking,
        bool $clickTracking,
    ): void {
        $configSetName = 'mailautomation-identity-'.$identity->id.'-'.Str::uuid()->toString();
        $aws = $this->awsCredentialsFor($credential);

        $configResult = $this->configurationSetService->createConfigurationSet(
            $configSetName,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $credential->region,
            $aws->getSecurityToken()
        );

        if (! $configResult['success']) {
            throw EmailIdentityProvisioningException::awsFailed($configResult['error'] ?? 'Failed to create configuration set.');
        }

        if ($credential->sns_topic_arn) {
            $eventResult = $this->configurationSetService->createSnsEventDestination(
                $configSetName,
                'sns-webhook',
                $credential->sns_topic_arn,
                $this->configurationSetService->getEventTypesForTracking($openTracking, $clickTracking),
                $aws->getAccessKeyId(),
                $aws->getSecretKey(),
                $credential->region,
                $aws->getSecurityToken()
            );

            if (! $eventResult['success']) {
                $this->configurationSetService->deleteConfigurationSet(
                    $configSetName,
                    $aws->getAccessKeyId(),
                    $aws->getSecretKey(),
                    $credential->region,
                    $aws->getSecurityToken()
                );

                throw EmailIdentityProvisioningException::awsFailed($eventResult['error'] ?? 'Failed to create configuration set.');
            }
        }

        $identity->update([
            'configuration_set_name' => $configSetName,
            'configuration_set_created_at' => now(),
            'open_tracking_enabled' => $openTracking,
            'click_tracking_enabled' => $clickTracking,
        ]);
    }

    /**
     * @throws EmailIdentityProvisioningException
     */
    private function associateIdentityWithTenant(EmailIdentity $identity, EmailCredential $credential): void
    {
        if (! $credential->tenant_name) {
            return;
        }

        if (! $credential->aws_account_id || ! $identity->configuration_set_name) {
            throw EmailIdentityProvisioningException::awsFailed('Missing AWS account ID or configuration set name for tenant association.');
        }

        $aws = $this->awsCredentialsFor($credential);

        $result = $this->tenantService->associateIdentityWithTenant(
            $credential->tenant_name,
            $credential->region,
            $credential->aws_account_id,
            $identity->domain,
            $identity->configuration_set_name,
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $aws->getSecurityToken(),
        );

        if (! $result['success']) {
            throw EmailIdentityProvisioningException::awsFailed($result['error'] ?? 'Failed to associate identity with SES tenant.');
        }

        $identity->update(['tenant_associated_at' => now()]);
    }
}
