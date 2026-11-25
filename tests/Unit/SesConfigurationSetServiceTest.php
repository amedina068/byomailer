<?php

namespace Tests\Unit;

use App\Services\SesConfigurationSetService;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Mockery;
use Tests\TestCase;

class SesConfigurationSetServiceTest extends TestCase
{
    public function test_create_configuration_set_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createConfigurationSet')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['ConfigurationSetName'] === 'mailautomation-123-abc123';
            }))
            ->andReturn(new Result([]));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createConfigurationSet(
            'mailautomation-123-abc123',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_create_configuration_set_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createConfigurationSet')
            ->once()
            ->andThrow(new AwsException(
                'ConfigurationSetAlreadyExists',
                new Command('CreateConfigurationSet'),
                ['message' => 'Configuration set already exists']
            ));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createConfigurationSet(
            'mailautomation-123-abc123',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Configuration set already exists', $result['error']);
    }

    public function test_get_configuration_set_returns_exists_true(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getConfigurationSet')
            ->once()
            ->with(['ConfigurationSetName' => 'mailautomation-123-abc123'])
            ->andReturn(new Result(['ConfigurationSetName' => 'mailautomation-123-abc123']));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getConfigurationSet(
            'mailautomation-123-abc123',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['exists']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_get_configuration_set_returns_exists_false_when_not_found(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $exception = new AwsException(
            'NotFoundException',
            new Command('GetConfigurationSet'),
            ['code' => 'NotFoundException', 'message' => 'Configuration set not found']
        );
        $mockClient->shouldReceive('getConfigurationSet')
            ->once()
            ->andThrow($exception);

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getConfigurationSet(
            'nonexistent-config-set',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['exists']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_get_configuration_set_returns_error_on_other_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getConfigurationSet')
            ->once()
            ->andThrow(new AwsException(
                'AccessDenied',
                new Command('GetConfigurationSet'),
                ['message' => 'Access denied']
            ));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->getConfigurationSet(
            'mailautomation-123-abc123',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['error']);
    }

    public function test_delete_configuration_set_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('deleteConfigurationSet')
            ->once()
            ->with(['ConfigurationSetName' => 'mailautomation-123-abc123'])
            ->andReturn(new Result([]));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->deleteConfigurationSet(
            'mailautomation-123-abc123',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_delete_configuration_set_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('deleteConfigurationSet')
            ->once()
            ->andThrow(new AwsException(
                'NotFoundException',
                new Command('DeleteConfigurationSet'),
                ['message' => 'Configuration set not found']
            ));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->deleteConfigurationSet(
            'nonexistent-config-set',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Configuration set not found', $result['error']);
    }

    public function test_create_sns_event_destination_returns_success(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createConfigurationSetEventDestination')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['ConfigurationSetName'] === 'mailautomation-123-abc123'
                    && $args['EventDestination']['SnsDestination']['TopicArn'] === 'arn:aws:sns:us-east-1:123456789012:mailautomation';
            }))
            ->andReturn(new Result([]));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createSnsEventDestination(
            'mailautomation-123-abc123',
            'sns-webhook',
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ['SEND'],
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_create_sns_event_destination_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('createConfigurationSetEventDestination')
            ->once()
            ->andThrow(new AwsException(
                'AccessDenied',
                new Command('CreateConfigurationSetEventDestination'),
                ['message' => 'Access denied']
            ));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createSnsEventDestination(
            'mailautomation-123-abc123',
            'sns-webhook',
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            ['SEND'],
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['error']);
    }

    public function test_get_default_event_types_returns_expected_events(): void
    {
        $service = new SesConfigurationSetService;

        $events = $service->getDefaultEventTypes();

        $this->assertSame([
            'SEND',
            'DELIVERY',
            'OPEN',
            'CLICK',
            'BOUNCE',
            'COMPLAINT',
            'REJECT',
            'RENDERING_FAILURE',
        ], $events);
    }

    public function test_put_tracking_options_forces_https_for_open_and_click_tracking(): void
    {
        $captured = null;

        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('putConfigurationSetTrackingOptions')
            ->once()
            ->with(Mockery::on(function ($args) use (&$captured): bool {
                $captured = $args;

                return true;
            }))
            ->andReturn(new Result([]));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->putTrackingOptions(
            'mailautomation-123-abc123',
            'track.example.com',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('track.example.com', $captured['CustomRedirectDomain']);
        $this->assertSame('REQUIRE', $captured['HttpsPolicy']);
    }

    public function test_enable_disabled_sns_event_destinations_enables_only_disabled_sns_destinations(): void
    {
        $snsDestination = ['TopicArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation-1-events'];

        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getConfigurationSetEventDestinations')
            ->once()
            ->with(['ConfigurationSetName' => 'mailautomation-1-abc'])
            ->andReturn(new Result(['EventDestinations' => [
                ['Name' => 'sns-webhook', 'Enabled' => false, 'MatchingEventTypes' => ['SEND', 'DELIVERY', 'BOUNCE'], 'SnsDestination' => $snsDestination],
                ['Name' => 'sns-enabled', 'Enabled' => true, 'MatchingEventTypes' => ['SEND'], 'SnsDestination' => $snsDestination],
                ['Name' => 'cloudwatch', 'Enabled' => false, 'MatchingEventTypes' => ['SEND'], 'CloudWatchDestination' => []],
            ]]));
        $mockClient->shouldReceive('updateConfigurationSetEventDestination')
            ->once()
            ->with([
                'ConfigurationSetName' => 'mailautomation-1-abc',
                'EventDestinationName' => 'sns-webhook',
                'EventDestination' => [
                    'Enabled' => true,
                    'MatchingEventTypes' => ['SEND', 'DELIVERY', 'BOUNCE'],
                    'SnsDestination' => $snsDestination,
                ],
            ])
            ->andReturn(new Result([]));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->enableDisabledSnsEventDestinations('mailautomation-1-abc', 'AKIAIOSFODNN7EXAMPLE', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
        $this->assertSame(['sns-webhook'], $result['enabled']);
    }

    public function test_enable_disabled_sns_event_destinations_changes_nothing_when_all_are_enabled(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getConfigurationSetEventDestinations')
            ->once()
            ->andReturn(new Result(['EventDestinations' => [
                ['Name' => 'sns-webhook', 'Enabled' => true, 'MatchingEventTypes' => ['SEND'], 'SnsDestination' => ['TopicArn' => 'arn:aws:sns:us-east-1:1:t']],
            ]]));
        $mockClient->shouldNotReceive('updateConfigurationSetEventDestination');

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->enableDisabledSnsEventDestinations('mailautomation-1-abc', 'AKIAIOSFODNN7EXAMPLE', 'secret', 'us-east-1');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['enabled']);
    }

    public function test_enable_disabled_sns_event_destinations_returns_error_on_aws_exception(): void
    {
        $mockClient = Mockery::mock(SesV2Client::class);
        $mockClient->shouldReceive('getConfigurationSetEventDestinations')
            ->once()
            ->andThrow(new AwsException(
                'NotFoundException',
                new Command('GetConfigurationSetEventDestinations'),
                ['message' => 'Configuration set does not exist']
            ));

        $service = new SesConfigurationSetService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->enableDisabledSnsEventDestinations('mailautomation-1-abc', 'AKIAIOSFODNN7EXAMPLE', 'secret', 'us-east-1');

        $this->assertFalse($result['success']);
        $this->assertSame('Configuration set does not exist', $result['error']);
    }
}
