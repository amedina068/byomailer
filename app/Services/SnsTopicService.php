<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Closure;

class SnsTopicService
{
    private ?Closure $clientFactory = null;

    /**
     * @param  Closure(array<string, mixed>): SnsClient  $factory
     */
    public function setClientFactory(Closure $factory): void
    {
        $this->clientFactory = $factory;
    }

    /**
     * @return array{success: bool, topic_arn?: string, error?: string}
     */
    public function createTopic(
        string $name,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);
            $result = $client->createTopic(['Name' => $name]);

            return [
                'success' => true,
                'topic_arn' => $result->get('TopicArn'),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, subscription_arn?: string, error?: string}
     */
    public function subscribeEndpoint(
        string $topicArn,
        string $endpointUrl,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);
            $protocol = $this->protocolForEndpoint($endpointUrl);

            $result = $client->subscribe([
                'TopicArn' => $topicArn,
                'Protocol' => $protocol,
                'Endpoint' => $endpointUrl,
                'ReturnSubscriptionArn' => true,
            ]);

            return [
                'success' => true,
                'subscription_arn' => $result->get('SubscriptionArn'),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, subscription_arn?: string, error?: string}
     */
    public function subscribeSqsEndpoint(
        string $topicArn,
        string $sqsQueueArn,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);

            $result = $client->subscribe([
                'TopicArn' => $topicArn,
                'Protocol' => 'sqs',
                'Endpoint' => $sqsQueueArn,
                'ReturnSubscriptionArn' => true,
            ]);

            return [
                'success' => true,
                'subscription_arn' => $result->get('SubscriptionArn'),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function unsubscribe(
        string $subscriptionArn,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);
            $client->unsubscribe(['SubscriptionArn' => $subscriptionArn]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function deleteTopic(
        string $topicArn,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region);
            $client->deleteTopic(['TopicArn' => $topicArn]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    private function protocolForEndpoint(string $endpoint): string
    {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);

        return $scheme === 'http' ? 'http' : 'https';
    }

    private function createClient(string $accessKey, string $secretKey, string $region): SnsClient
    {
        $config = [
            'version' => '2010-03-31',
            'region' => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ];

        if ($this->clientFactory) {
            return ($this->clientFactory)($config);
        }

        return new SnsClient($config);
    }
}
