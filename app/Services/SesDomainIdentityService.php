<?php

namespace App\Services;

use App\Enums\DkimStatus;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Closure;

class SesDomainIdentityService
{
    /**
     * The DKIM domain that is the default for SES regions.
     */
    public const DEFAULT_DKIM_DOMAIN = 'dkim.amazonses.com';

    /**
     * Regions where SES uses a regional DKIM domain.
     *
     * Source: "DKIM domains" table in the Amazon SES endpoints and quotas page
     * (https://docs.aws.amazon.com/general/latest/gr/ses.html), read on 2026-11-06.
     * Use this map only when AWS does not return SigningHostedZone.
     *
     * @var array<string, string>
     */
    public const REGIONAL_DKIM_DOMAINS = [
        'af-south-1' => 'dkim.af-south-1.amazonses.com',
        'ap-northeast-3' => 'dkim.ap-northeast-3.amazonses.com',
        'ap-south-2' => 'dkim.ap-south-2.amazonses.com',
        'ap-southeast-3' => 'dkim.ap-southeast-3.amazonses.com',
        'ap-southeast-5' => 'dkim.ap-southeast-5.amazonses.com',
        'ca-west-1' => 'dkim.ca-west-1.amazonses.com',
        'eu-central-2' => 'dkim.eu-central-2.amazonses.com',
        'eu-south-1' => 'dkim.eu-south-1.amazonses.com',
        'il-central-1' => 'dkim.il-central-1.amazonses.com',
        'me-central-1' => 'dkim.me-central-1.amazonses.com',
        'us-gov-east-1' => 'dkim.us-gov-east-1.amazonses.com',
    ];

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
     * Create a domain identity with DKIM enabled.
     *
     * @return array{success: bool, dkim_tokens?: array<int, string>, dkim_signing_hosted_zone?: string|null, error?: string}
     */
    public function createDomainIdentity(
        string $domain,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $result = $client->createEmailIdentity([
                'EmailIdentity' => $domain,
                'DkimSigningAttributes' => [
                    'NextSigningKeyLength' => 'RSA_2048_BIT',
                ],
            ]);

            $dkimAttributes = $result->get('DkimAttributes');
            $tokens = $dkimAttributes['Tokens'] ?? [];

            return [
                'success' => true,
                'dkim_tokens' => $tokens,
                'dkim_signing_hosted_zone' => $dkimAttributes['SigningHostedZone'] ?? null,
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Set the custom MAIL FROM domain for an identity.
     *
     * @return array{success: bool, error?: string}
     */
    public function setMailFromDomain(
        string $identity,
        string $mailFromSubdomain,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $client->putEmailIdentityMailFromAttributes([
                'EmailIdentity' => $identity,
                'MailFromDomain' => $mailFromSubdomain,
                'BehaviorOnMxFailure' => 'USE_DEFAULT_VALUE',
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
     * Get the DKIM DNS records for a domain.
     *
     * The CNAME target uses the SigningHostedZone that AWS returns. If AWS does not
     * return it, the target uses the DKIM domain of the region.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, array{type: string, name: string, value: string}>
     */
    public function getDkimRecords(string $domain, array $tokens, ?string $signingHostedZone = null, ?string $region = null): array
    {
        $dkimDomain = $this->resolveDkimDomain($signingHostedZone, $region);

        return array_map(fn (string $token) => [
            'type' => 'CNAME',
            'name' => "{$token}._domainkey.{$domain}",
            'value' => "{$token}.{$dkimDomain}",
        ], $tokens);
    }

    /**
     * Get the DKIM domain that the DKIM CNAME records point to.
     */
    public function resolveDkimDomain(?string $signingHostedZone, ?string $region): string
    {
        $hostedZone = strtolower(trim((string) $signingHostedZone, " \t\n\r\0\x0B."));

        if ($hostedZone !== '') {
            return $hostedZone;
        }

        return self::REGIONAL_DKIM_DOMAINS[strtolower(trim((string) $region))] ?? self::DEFAULT_DKIM_DOMAIN;
    }

    /**
     * Get the MAIL FROM DNS records (MX and SPF TXT).
     *
     * @return array<int, array{type: string, name: string, value: string, priority?: int}>
     */
    public function getMailFromRecords(string $mailFromSubdomain, string $region): array
    {
        return [
            [
                'type' => 'MX',
                'name' => $mailFromSubdomain,
                'value' => "feedback-smtp.{$region}.amazonses.com",
                'priority' => 10,
            ],
            [
                'type' => 'TXT',
                'name' => $mailFromSubdomain,
                'value' => 'v=spf1 include:amazonses.com ~all',
            ],
        ];
    }

    /**
     * Get the DMARC DNS record for a domain.
     *
     * @return array{type: string, name: string, value: string}
     */
    public function getDmarcRecord(string $domain): array
    {
        return [
            'type' => 'TXT',
            'name' => "_dmarc.{$domain}",
            'value' => 'v=DMARC1; p=none;',
        ];
    }

    /**
     * List domain identities for an AWS account.
     *
     * @return array{success: bool, identities?: array<int, array{name: string, sending_enabled: bool, verification_status: string}>, error?: string}
     */
    public function listDomainIdentities(
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $result = $client->listEmailIdentities([
                'PageSize' => 100,
            ]);

            $rows = $result->get('EmailIdentities') ?? [];

            $identities = [];
            foreach ($rows as $row) {
                if (($row['IdentityType'] ?? null) !== 'DOMAIN') {
                    continue;
                }

                $identities[] = [
                    'name' => $row['IdentityName'] ?? '',
                    'sending_enabled' => (bool) ($row['SendingEnabled'] ?? false),
                    'verification_status' => $row['VerificationStatus'] ?? 'PENDING',
                ];
            }

            return [
                'success' => true,
                'identities' => $identities,
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Get the identity verification status (and DKIM tokens / MAIL FROM domain if set).
     *
     * @return array{success: bool, status?: string, dkim_status?: string, dkim_tokens?: array<int, string>, dkim_signing_hosted_zone?: string|null, mail_from_domain?: string|null, mail_from_status?: string|null, error?: string}
     */
    public function getIdentityStatus(
        string $identity,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $result = $client->getEmailIdentity([
                'EmailIdentity' => $identity,
            ]);

            $dkimAttributes = $result->get('DkimAttributes') ?? [];
            $mailFromAttributes = $result->get('MailFromAttributes') ?? [];

            return [
                'success' => true,
                'status' => $result->get('VerifiedForSendingStatus') ? 'verified' : 'pending',
                'dkim_status' => $dkimAttributes['Status'] ?? DkimStatus::Pending->value,
                'dkim_tokens' => $dkimAttributes['Tokens'] ?? [],
                'dkim_signing_hosted_zone' => $dkimAttributes['SigningHostedZone'] ?? null,
                'mail_from_domain' => $mailFromAttributes['MailFromDomain'] ?? null,
                'mail_from_status' => $mailFromAttributes['MailFromDomainStatus'] ?? null,
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
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
