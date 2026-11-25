<?php

namespace Tests\Unit;

use App\Services\SesDomainIdentityService;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Mockery;
use Tests\TestCase;

class SesDomainIdentityServiceTest extends TestCase
{
    public function test_create_domain_identity_returns_dkim_tokens_on_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createEmailIdentity')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['EmailIdentity'] === 'example.com'
                    && $args['DkimSigningAttributes']['NextSigningKeyLength'] === 'RSA_2048_BIT';
            }))
            ->andReturn(new Result([
                'DkimAttributes' => [
                    'Tokens' => [
                        'token1abcdefg',
                        'token2hijklmn',
                        'token3opqrstu',
                    ],
                    'Status' => 'PENDING',
                    'SigningHostedZone' => 'dkim.me-central-1.amazonses.com',
                ],
                'IdentityType' => 'DOMAIN',
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createDomainIdentity(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['dkim_tokens']);
        $this->assertContains('token1abcdefg', $result['dkim_tokens']);
        $this->assertSame('dkim.me-central-1.amazonses.com', $result['dkim_signing_hosted_zone']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_create_domain_identity_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createEmailIdentity')
            ->once()
            ->andThrow(new AwsException(
                'Identity already exists',
                new Command('CreateEmailIdentity'),
                ['message' => 'Identity already exists']
            ));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createDomainIdentity(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Identity already exists', $result['error']);
        $this->assertArrayNotHasKey('dkim_tokens', $result);
    }

    public function test_get_dkim_records_formats_records_correctly(): void
    {
        $service = new SesDomainIdentityService;

        $tokens = ['abc123', 'def456', 'ghi789'];
        $records = $service->getDkimRecords('example.com', $tokens);

        $this->assertCount(3, $records);

        $this->assertSame('CNAME', $records[0]['type']);
        $this->assertSame('abc123._domainkey.example.com', $records[0]['name']);
        $this->assertSame('abc123.dkim.amazonses.com', $records[0]['value']);

        $this->assertSame('CNAME', $records[1]['type']);
        $this->assertSame('def456._domainkey.example.com', $records[1]['name']);
        $this->assertSame('def456.dkim.amazonses.com', $records[1]['value']);

        $this->assertSame('CNAME', $records[2]['type']);
        $this->assertSame('ghi789._domainkey.example.com', $records[2]['name']);
        $this->assertSame('ghi789.dkim.amazonses.com', $records[2]['value']);
    }

    public function test_get_dkim_records_uses_signing_hosted_zone_from_aws(): void
    {
        $service = new SesDomainIdentityService;

        $records = $service->getDkimRecords('example.com', ['abc123'], 'dkim.eu-south-1.amazonses.com.', 'us-east-1');

        $this->assertSame('abc123._domainkey.example.com', $records[0]['name']);
        $this->assertSame('abc123.dkim.eu-south-1.amazonses.com', $records[0]['value']);
    }

    public function test_get_dkim_records_uses_regional_dkim_domain_when_hosted_zone_is_missing(): void
    {
        $service = new SesDomainIdentityService;

        $records = $service->getDkimRecords('example.com', ['abc123', 'def456'], null, 'ap-southeast-3');

        $this->assertSame('abc123.dkim.ap-southeast-3.amazonses.com', $records[0]['value']);
        $this->assertSame('def456.dkim.ap-southeast-3.amazonses.com', $records[1]['value']);
    }

    public function test_get_dkim_records_uses_default_dkim_domain_for_standard_region(): void
    {
        $service = new SesDomainIdentityService;

        $records = $service->getDkimRecords('example.com', ['abc123'], null, 'eu-west-1');

        $this->assertSame('abc123.dkim.amazonses.com', $records[0]['value']);
    }

    public function test_get_identity_status_returns_status_on_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getEmailIdentity')
            ->once()
            ->with(['EmailIdentity' => 'example.com'])
            ->andReturn(new Result([
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => [
                    'Status' => 'SUCCESS',
                    'Tokens' => ['tok1', 'tok2', 'tok3'],
                    'SigningHostedZone' => 'dkim.il-central-1.amazonses.com',
                ],
                'MailFromAttributes' => [
                    'MailFromDomain' => 'bounces.example.com',
                    'MailFromDomainStatus' => 'SUCCESS',
                ],
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getIdentityStatus(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('SUCCESS', $result['dkim_status']);
        $this->assertSame(['tok1', 'tok2', 'tok3'], $result['dkim_tokens']);
        $this->assertSame('dkim.il-central-1.amazonses.com', $result['dkim_signing_hosted_zone']);
        $this->assertSame('bounces.example.com', $result['mail_from_domain']);
        $this->assertSame('SUCCESS', $result['mail_from_status']);
    }

    public function test_get_identity_status_returns_null_mail_from_when_not_configured(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getEmailIdentity')
            ->once()
            ->andReturn(new Result([
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => [
                    'Status' => 'SUCCESS',
                    'Tokens' => ['a', 'b', 'c'],
                ],
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getIdentityStatus(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['mail_from_domain']);
        $this->assertNull($result['mail_from_status']);
        $this->assertSame(['a', 'b', 'c'], $result['dkim_tokens']);
    }

    public function test_get_identity_status_returns_pending_when_not_verified(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getEmailIdentity')
            ->once()
            ->andReturn(new Result([
                'VerifiedForSendingStatus' => false,
                'DkimAttributes' => [
                    'Status' => 'PENDING',
                ],
                'MailFromAttributes' => [
                    'MailFromDomainStatus' => 'PENDING',
                ],
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getIdentityStatus(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('PENDING', $result['dkim_status']);
        $this->assertSame('PENDING', $result['mail_from_status']);
    }

    public function test_get_identity_status_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getEmailIdentity')
            ->once()
            ->andThrow(new AwsException(
                'Identity not found',
                new Command('GetEmailIdentity'),
                ['message' => 'Identity not found']
            ));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getIdentityStatus(
            'nonexistent.example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Identity not found', $result['error']);
    }

    public function test_set_mail_from_domain_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('putEmailIdentityMailFromAttributes')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['EmailIdentity'] === 'example.com'
                    && $args['MailFromDomain'] === 'mas.example.com'
                    && $args['BehaviorOnMxFailure'] === 'USE_DEFAULT_VALUE';
            }))
            ->andReturn(new Result([]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->setMailFromDomain(
            'example.com',
            'mas.example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_set_mail_from_domain_returns_error_on_failure(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('putEmailIdentityMailFromAttributes')
            ->once()
            ->andThrow(new AwsException(
                'Invalid mail from domain',
                new Command('PutEmailIdentityMailFromAttributes'),
                ['message' => 'Invalid mail from domain']
            ));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->setMailFromDomain(
            'example.com',
            'invalid',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid mail from domain', $result['error']);
    }

    public function test_get_mail_from_records_formats_records_correctly(): void
    {
        $service = new SesDomainIdentityService;

        $records = $service->getMailFromRecords('mas.example.com', 'us-east-1');

        $this->assertCount(2, $records);

        $this->assertSame('MX', $records[0]['type']);
        $this->assertSame('mas.example.com', $records[0]['name']);
        $this->assertSame('feedback-smtp.us-east-1.amazonses.com', $records[0]['value']);
        $this->assertSame(10, $records[0]['priority']);

        $this->assertSame('TXT', $records[1]['type']);
        $this->assertSame('mas.example.com', $records[1]['name']);
        $this->assertSame('v=spf1 include:amazonses.com ~all', $records[1]['value']);
    }

    public function test_get_dmarc_record_formats_correctly(): void
    {
        $service = new SesDomainIdentityService;

        $record = $service->getDmarcRecord('example.com');

        $this->assertSame('TXT', $record['type']);
        $this->assertSame('_dmarc.example.com', $record['name']);
        $this->assertSame('v=DMARC1; p=none;', $record['value']);
    }

    public function test_list_domain_identities_returns_only_domain_rows(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('listEmailIdentities')
            ->once()
            ->with(['PageSize' => 100])
            ->andReturn(new Result([
                'EmailIdentities' => [
                    [
                        'IdentityType' => 'DOMAIN',
                        'IdentityName' => 'example.com',
                        'SendingEnabled' => true,
                        'VerificationStatus' => 'SUCCESS',
                    ],
                    [
                        'IdentityType' => 'EMAIL_ADDRESS',
                        'IdentityName' => 'someone@example.com',
                        'SendingEnabled' => true,
                        'VerificationStatus' => 'SUCCESS',
                    ],
                    [
                        'IdentityType' => 'DOMAIN',
                        'IdentityName' => 'pending.test',
                        'SendingEnabled' => false,
                        'VerificationStatus' => 'PENDING',
                    ],
                ],
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->listDomainIdentities(
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['identities']);
        $this->assertSame('example.com', $result['identities'][0]['name']);
        $this->assertTrue($result['identities'][0]['sending_enabled']);
        $this->assertSame('SUCCESS', $result['identities'][0]['verification_status']);
        $this->assertSame('pending.test', $result['identities'][1]['name']);
        $this->assertFalse($result['identities'][1]['sending_enabled']);
        $this->assertSame('PENDING', $result['identities'][1]['verification_status']);
    }

    public function test_list_domain_identities_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('listEmailIdentities')
            ->once()
            ->andThrow(new AwsException(
                'AccessDenied',
                new Command('ListEmailIdentities'),
                ['message' => 'User is not authorized to perform ses:ListEmailIdentities']
            ));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->listDomainIdentities(
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('User is not authorized to perform ses:ListEmailIdentities', $result['error']);
    }

    public function test_get_identity_status_includes_mail_from_status(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getEmailIdentity')
            ->once()
            ->with(['EmailIdentity' => 'example.com'])
            ->andReturn(new Result([
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => [
                    'Status' => 'SUCCESS',
                ],
                'MailFromAttributes' => [
                    'MailFromDomain' => 'mas.example.com',
                    'MailFromDomainStatus' => 'SUCCESS',
                ],
            ]));

        $service = new SesDomainIdentityService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getIdentityStatus(
            'example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('SUCCESS', $result['dkim_status']);
        $this->assertSame('SUCCESS', $result['mail_from_status']);
    }
}
