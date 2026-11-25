<?php

namespace Tests\Unit;

use App\Services\AwsCredentialValidator;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Mockery;
use Tests\TestCase;

class AwsCredentialValidatorTest extends TestCase
{
    public function test_validate_returns_valid_with_account_id_on_success(): void
    {
        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(new Result([
                'Account' => '123456789012',
                'Arn' => 'arn:aws:iam::123456789012:user/test',
                'UserId' => 'AIDAEXAMPLE',
            ]));

        $mockSesV2Client = Mockery::mock(SesV2Client::class);
        $mockSesV2Client->shouldReceive('listConfigurationSets')
            ->once()
            ->with(['PageSize' => 1])
            ->andReturn(new Result(['ConfigurationSets' => []]));

        $mockSesV1Client = Mockery::mock(SesClient::class);
        $mockSesV1Client->shouldReceive('listTemplates')
            ->once()
            ->with(['MaxItems' => 1])
            ->andReturn(new Result(['TemplatesMetadata' => []]));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(fn ($config) => $mockStsClient);
        $validator->setSesClientFactory(fn ($config) => $mockSesV2Client);
        $validator->setSesV1ClientFactory(fn ($config) => $mockSesV1Client);

        $result = $validator->validate('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

        $this->assertTrue($result['valid']);
        $this->assertSame('123456789012', $result['account_id']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_validate_returns_invalid_with_error_on_sts_exception(): void
    {
        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andThrow(new AwsException(
                'The security token included in the request is invalid.',
                new Command('GetCallerIdentity'),
                ['message' => 'The security token included in the request is invalid.']
            ));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(fn ($config) => $mockStsClient);

        $result = $validator->validate('AKIAINVALIDEXAMPLE01', 'invalid-secret-key-EXAMPLE-EXAMPLE-EXAMPLE', 'us-east-1');

        $this->assertFalse($result['valid']);
        $this->assertSame('The security token included in the request is invalid.', $result['error']);
        $this->assertArrayNotHasKey('account_id', $result);
    }

    public function test_validate_returns_invalid_with_generic_error_when_no_aws_message(): void
    {
        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andThrow(new AwsException(
                'Connection error',
                new Command('GetCallerIdentity')
            ));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(fn ($config) => $mockStsClient);

        $result = $validator->validate('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['error']);
        $this->assertArrayNotHasKey('account_id', $result);
    }

    public function test_validate_returns_invalid_when_ses_access_denied(): void
    {
        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(new Result([
                'Account' => '123456789012',
                'Arn' => 'arn:aws:iam::123456789012:user/test',
                'UserId' => 'AIDAEXAMPLE',
            ]));

        $mockSesClient = Mockery::mock(SesV2Client::class);
        $mockSesClient->shouldReceive('listConfigurationSets')
            ->once()
            ->andThrow(new AwsException(
                'User: arn:aws:iam::123456789012:user/test is not authorized to perform: ses:ListConfigurationSets',
                new Command('ListConfigurationSets'),
                ['message' => 'User: arn:aws:iam::123456789012:user/test is not authorized to perform: ses:ListConfigurationSets']
            ));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(fn ($config) => $mockStsClient);
        $validator->setSesClientFactory(fn ($config) => $mockSesClient);

        $result = $validator->validate('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('SES', $result['error']);
        $this->assertStringContainsString('ses:CreateConfigurationSet', $result['error']);
        $this->assertArrayNotHasKey('account_id', $result);
    }

    public function test_validate_returns_invalid_when_ses_template_access_denied(): void
    {
        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(new Result([
                'Account' => '123456789012',
                'Arn' => 'arn:aws:iam::123456789012:user/test',
                'UserId' => 'AIDAEXAMPLE',
            ]));

        $mockSesV2Client = Mockery::mock(SesV2Client::class);
        $mockSesV2Client->shouldReceive('listConfigurationSets')
            ->once()
            ->andReturn(new Result(['ConfigurationSets' => []]));

        $mockSesV1Client = Mockery::mock(SesClient::class);
        $mockSesV1Client->shouldReceive('listTemplates')
            ->once()
            ->andThrow(new AwsException(
                'User: arn:aws:iam::123456789012:user/test is not authorized to perform: ses:ListTemplates',
                new Command('ListTemplates'),
                ['message' => 'User: arn:aws:iam::123456789012:user/test is not authorized to perform: ses:ListTemplates']
            ));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(fn ($config) => $mockStsClient);
        $validator->setSesClientFactory(fn ($config) => $mockSesV2Client);
        $validator->setSesV1ClientFactory(fn ($config) => $mockSesV1Client);

        $result = $validator->validate('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('template', $result['error']);
        $this->assertStringContainsString('ses:CreateTemplate', $result['error']);
        $this->assertArrayNotHasKey('account_id', $result);
    }

    public function test_validate_passes_correct_config_to_client_factories(): void
    {
        $capturedStsConfig = null;
        $capturedSesV2Config = null;
        $capturedSesV1Config = null;

        $mockStsClient = Mockery::mock(StsClient::class);
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(new Result(['Account' => '123456789012']));

        $mockSesV2Client = Mockery::mock(SesV2Client::class);
        $mockSesV2Client->shouldReceive('listConfigurationSets')
            ->once()
            ->andReturn(new Result(['ConfigurationSets' => []]));

        $mockSesV1Client = Mockery::mock(SesClient::class);
        $mockSesV1Client->shouldReceive('listTemplates')
            ->once()
            ->andReturn(new Result(['TemplatesMetadata' => []]));

        $validator = new AwsCredentialValidator;
        $validator->setStsClientFactory(function ($config) use (&$capturedStsConfig, $mockStsClient) {
            $capturedStsConfig = $config;

            return $mockStsClient;
        });
        $validator->setSesClientFactory(function ($config) use (&$capturedSesV2Config, $mockSesV2Client) {
            $capturedSesV2Config = $config;

            return $mockSesV2Client;
        });
        $validator->setSesV1ClientFactory(function ($config) use (&$capturedSesV1Config, $mockSesV1Client) {
            $capturedSesV1Config = $config;

            return $mockSesV1Client;
        });

        $validator->validate('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'eu-west-1');

        $this->assertSame('2011-06-15', $capturedStsConfig['version']);
        $this->assertSame('eu-west-1', $capturedStsConfig['region']);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $capturedStsConfig['credentials']['key']);
        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $capturedStsConfig['credentials']['secret']);

        $this->assertSame('2019-09-27', $capturedSesV2Config['version']);
        $this->assertSame('eu-west-1', $capturedSesV2Config['region']);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $capturedSesV2Config['credentials']['key']);
        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $capturedSesV2Config['credentials']['secret']);

        $this->assertSame('2010-12-01', $capturedSesV1Config['version']);
        $this->assertSame('eu-west-1', $capturedSesV1Config['region']);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $capturedSesV1Config['credentials']['key']);
        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $capturedSesV1Config['credentials']['secret']);
    }
}
