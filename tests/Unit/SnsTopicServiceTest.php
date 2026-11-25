<?php

namespace Tests\Unit;

use App\Services\SnsTopicService;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sns\SnsClient;
use Mockery;
use Tests\TestCase;

class SnsTopicServiceTest extends TestCase
{
    public function test_create_topic_returns_success(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('createTopic')
            ->once()
            ->with(['Name' => 'mailautomation'])
            ->andReturn(new Result(['TopicArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation']));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createTopic(
            'mailautomation',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation', $result['topic_arn']);
    }

    public function test_create_topic_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('createTopic')
            ->once()
            ->andThrow(new AwsException(
                'AccessDenied',
                new Command('CreateTopic'),
                ['message' => 'Access denied']
            ));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->createTopic(
            'mailautomation',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['error']);
    }

    public function test_subscribe_endpoint_returns_success(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('subscribe')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['TopicArn'] === 'arn:aws:sns:us-east-1:123456789012:mailautomation'
                    && $args['Protocol'] === 'https'
                    && $args['Endpoint'] === 'https://example.com/webhook'
                    && $args['ReturnSubscriptionArn'] === true;
            }))
            ->andReturn(new Result(['SubscriptionArn' => 'pending confirmation']));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->subscribeEndpoint(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'https://example.com/webhook',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pending confirmation', $result['subscription_arn']);
    }

    public function test_subscribe_endpoint_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('subscribe')
            ->once()
            ->andThrow(new AwsException(
                'InvalidParameter',
                new Command('Subscribe'),
                ['message' => 'Invalid endpoint']
            ));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->subscribeEndpoint(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'https://example.com/webhook',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid endpoint', $result['error']);
    }

    public function test_delete_topic_returns_success(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('deleteTopic')
            ->once()
            ->with(['TopicArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation'])
            ->andReturn(new Result([]));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->deleteTopic(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
    }

    public function test_subscribe_sqs_endpoint_returns_success(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('subscribe')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['TopicArn'] === 'arn:aws:sns:us-east-1:123456789012:mailautomation'
                    && $args['Protocol'] === 'sqs'
                    && $args['Endpoint'] === 'arn:aws:sqs:us-east-1:999999999999:ses-events'
                    && $args['ReturnSubscriptionArn'] === true;
            }))
            ->andReturn(new Result(['SubscriptionArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:sub-1234']));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->subscribeSqsEndpoint(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'arn:aws:sqs:us-east-1:999999999999:ses-events',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation:sub-1234', $result['subscription_arn']);
    }

    public function test_subscribe_sqs_endpoint_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('subscribe')
            ->once()
            ->andThrow(new AwsException(
                'InvalidParameter',
                new Command('Subscribe'),
                ['message' => 'Invalid SQS ARN']
            ));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->subscribeSqsEndpoint(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'invalid-arn',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid SQS ARN', $result['error']);
    }

    public function test_unsubscribe_returns_success(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('unsubscribe')
            ->once()
            ->with(['SubscriptionArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation:sub-1234'])
            ->andReturn(new Result([]));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->unsubscribe(
            'arn:aws:sns:us-east-1:123456789012:mailautomation:sub-1234',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertTrue($result['success']);
    }

    public function test_unsubscribe_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('unsubscribe')
            ->once()
            ->andThrow(new AwsException(
                'NotFound',
                new Command('Unsubscribe'),
                ['message' => 'Subscription not found']
            ));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->unsubscribe(
            'arn:aws:sns:us-east-1:123456789012:mailautomation:nonexistent',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Subscription not found', $result['error']);
    }

    public function test_delete_topic_returns_error_on_exception(): void
    {
        $mockClient = Mockery::mock(SnsClient::class);
        $mockClient->shouldReceive('deleteTopic')
            ->once()
            ->andThrow(new AwsException(
                'NotFound',
                new Command('DeleteTopic'),
                ['message' => 'Topic not found']
            ));

        $service = new SnsTopicService;
        $service->setClientFactory(fn ($config) => $mockClient);

        $result = $service->deleteTopic(
            'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Topic not found', $result['error']);
    }
}
