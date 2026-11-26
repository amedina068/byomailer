<?php

namespace Tests\Unit;

use App\Services\SesTenantService;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Mockery;
use Tests\TestCase;

class SesTenantServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createServiceWithMockClient(SesV2Client $mockClient): SesTenantService
    {
        $service = new SesTenantService;
        $service->setClientFactory(fn () => $mockClient);

        return $service;
    }

    public function test_create_tenant_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenant')
            ->with(['TenantName' => 'byomailer'])
            ->once()
            ->andReturn(new Result(['TenantArn' => 'arn:aws:ses:us-east-1:123456789012:tenant/abc123']));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->createTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
        $this->assertEquals('arn:aws:ses:us-east-1:123456789012:tenant/abc123', $result['tenant_arn']);
    }

    public function test_create_tenant_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenant')
            ->once()
            ->andThrow(new AwsException('Tenant limit exceeded', new Command('createTenant')));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->createTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_create_tenant_succeeds_when_already_exists(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenant')
            ->once()
            ->andThrow(new AwsException('Tenant already exists', new Command('createTenant'), [
                'code' => 'AlreadyExistsException',
            ]));
        $mockClient->shouldReceive('getTenant')
            ->with(['TenantName' => 'byomailer'])
            ->once()
            ->andReturn(new Result(['Tenant' => ['TenantArn' => 'arn:aws:ses:us-east-1:123456789012:tenant/abc123']]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->createTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
        $this->assertEquals('arn:aws:ses:us-east-1:123456789012:tenant/abc123', $result['tenant_arn']);
    }

    public function test_associate_resource_succeeds_when_already_associated(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->once()
            ->andThrow(new AwsException('Already associated', new Command('createTenantResourceAssociation'), [
                'code' => 'AlreadyExistsException',
            ]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateResource('byomailer', 'arn:test', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
    }

    public function test_associate_resource_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->with([
                'TenantName' => 'byomailer',
                'ResourceArn' => 'arn:aws:ses:us-east-1:123456789012:identity/example.com',
            ])
            ->once()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateResource(
            'byomailer',
            'arn:aws:ses:us-east-1:123456789012:identity/example.com',
            'key', 'secret', 'us-east-1'
        );

        $this->assertTrue($result['success']);
    }

    public function test_associate_resource_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->once()
            ->andThrow(new AwsException('Resource not found', new Command('createTenantResourceAssociation')));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateResource('byomailer', 'arn:test', 'key', 'secret', 'us-east-1');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_update_reputation_policy_returns_success(): void
    {
        $tenantArn = 'arn:aws:ses:us-east-1:123456789012:tenant/abc123';

        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('updateReputationEntityPolicy')
            ->with([
                'ReputationEntityType' => 'RESOURCE',
                'ReputationEntityReference' => $tenantArn,
                'ReputationEntityPolicy' => 'arn:aws:ses:us-east-1:aws:reputation-policy/standard',
            ])
            ->once()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->updateReputationPolicy($tenantArn, 'us-east-1', 'standard', 'key', 'secret');

        $this->assertTrue($result['success']);
    }

    public function test_build_identity_arn_formats_correctly(): void
    {
        $arn = SesTenantService::buildIdentityArn('us-east-1', '123456789012', 'example.com');

        $this->assertEquals('arn:aws:ses:us-east-1:123456789012:identity/example.com', $arn);
    }

    public function test_build_configuration_set_arn_formats_correctly(): void
    {
        $arn = SesTenantService::buildConfigurationSetArn('us-east-1', '123456789012', 'my-config-set');

        $this->assertEquals('arn:aws:ses:us-east-1:123456789012:configuration-set/my-config-set', $arn);
    }

    public function test_delete_tenant_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('deleteTenant')
            ->with(['TenantName' => 'byomailer'])
            ->once()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->deleteTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
    }

    public function test_get_tenant_returns_tenant_data(): void
    {
        $tenantData = [
            'TenantName' => 'byomailer',
            'SendingStatus' => 'ACTIVE',
        ];

        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getTenant')
            ->with(['TenantName' => 'byomailer'])
            ->once()
            ->andReturn(new Result($tenantData));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->getTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tenant', $result);
    }

    public function test_dissociate_resource_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('deleteTenantResourceAssociation')
            ->once()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->dissociateResource('byomailer', 'arn:test', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
    }

    public function test_provision_tenant_creates_and_sets_policy(): void
    {
        $tenantArn = 'arn:aws:ses:us-east-1:123456789012:tenant/abc123';

        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenant')
            ->with(['TenantName' => 'byomailer'])
            ->once()
            ->andReturn(new Result(['TenantArn' => $tenantArn]));
        $mockClient->shouldReceive('updateReputationEntityPolicy')
            ->with([
                'ReputationEntityType' => 'RESOURCE',
                'ReputationEntityReference' => $tenantArn,
                'ReputationEntityPolicy' => 'arn:aws:ses:us-east-1:aws:reputation-policy/standard',
            ])
            ->once()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->provisionTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
    }

    public function test_provision_tenant_returns_error_when_create_fails(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenant')
            ->once()
            ->andThrow(new AwsException('Access denied', new Command('createTenant')));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->provisionTenant('byomailer', 'key', 'secret', 'us-east-1');

        $this->assertFalse($result['success']);
    }

    public function test_associate_identity_with_tenant_succeeds(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->twice()
            ->andReturn(new Result([]));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateIdentityWithTenant(
            'byomailer', 'us-east-1', '123456789012', 'example.com', 'my-config-set', 'key', 'secret'
        );

        $this->assertTrue($result['success']);
    }

    public function test_associate_identity_with_tenant_does_not_set_default_configuration_set(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->twice()
            ->andReturn(new Result([]));
        $mockClient->shouldNotReceive('putEmailIdentityConfigurationSetAttributes');

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateIdentityWithTenant(
            'byomailer', 'us-east-1', '123456789012', 'example.com', 'my-config-set', 'key', 'secret'
        );

        $this->assertTrue($result['success']);
    }

    public function test_associate_identity_with_tenant_fails_on_identity_association(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createTenantResourceAssociation')
            ->once()
            ->andThrow(new AwsException('Not found', new Command('createTenantResourceAssociation')));

        $service = $this->createServiceWithMockClient($mockClient);

        $result = $service->associateIdentityWithTenant(
            'byomailer', 'us-east-1', '123456789012', 'example.com', 'my-config-set', 'key', 'secret'
        );

        $this->assertFalse($result['success']);
    }

    public function test_a_session_token_signs_the_call_of_a_role(): void
    {
        $configs = [];
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getTenant')->twice()->andReturn(new Result(['Tenant' => []]));

        $service = new SesTenantService;
        $service->setClientFactory(function (array $config) use (&$configs, $mockClient) {
            $configs[] = $config;

            return $mockClient;
        });

        $service->getTenant('byomailer', 'ASIAIOSFODNN7EXAMPLE', 'temporary-secret-EXAMPLE', 'us-east-1', 'temporary-token-EXAMPLE');
        $service->getTenant('byomailer', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

        $this->assertSame('temporary-token-EXAMPLE', $configs[0]['credentials']['token']);
        $this->assertArrayNotHasKey('token', $configs[1]['credentials']);
    }
}
