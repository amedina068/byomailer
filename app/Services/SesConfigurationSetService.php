<?php

namespace App\Services;

use App\Enums\SesEventType;
use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Closure;

class SesConfigurationSetService
{
    /**
     * Force open-pixel and click-redirect tracking URLs onto HTTPS.
     * The default (OPTIONAL) lets SES serve the open pixel over http://,
     * which is a spam signal and is blocked by clients on HTTPS pages.
     */
    private const TRACKING_HTTPS_POLICY = 'REQUIRE';

    private ?Closure $clientFactory = null;

    private ?Closure $v1ClientFactory = null;

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
     * Set a custom SES v1 client factory for testing.
     *
     * @param  Closure(array<string, mixed>): SesClient  $factory
     */
    public function setV1ClientFactory(Closure $factory): void
    {
        $this->v1ClientFactory = $factory;
    }

    /**
     * Create a Configuration Set.
     *
     * @return array{success: bool, error?: string}
     */
    public function createConfigurationSet(
        string $name,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->createConfigurationSet([
                'ConfigurationSetName' => $name,
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
     * Attach an SNS destination to a configuration set.
     *
     * @param  array<int, string>  $eventTypes
     * @return array{success: bool, error?: string}
     */
    public function createSnsEventDestination(
        string $configSetName,
        string $destinationName,
        string $topicArn,
        array $eventTypes,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->createConfigurationSetEventDestination([
                'ConfigurationSetName' => $configSetName,
                'EventDestinationName' => $destinationName,
                'EventDestination' => [
                    'Enabled' => true,
                    'MatchingEventTypes' => $eventTypes,
                    'SnsDestination' => [
                        'TopicArn' => $topicArn,
                    ],
                ],
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
     * Update an SNS destination on a configuration set.
     *
     * @param  array<int, string>  $eventTypes
     * @return array{success: bool, error?: string}
     */
    public function updateSnsEventDestination(
        string $configSetName,
        string $destinationName,
        string $topicArn,
        array $eventTypes,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->updateConfigurationSetEventDestination([
                'ConfigurationSetName' => $configSetName,
                'EventDestinationName' => $destinationName,
                'EventDestination' => [
                    'Enabled' => true,
                    'MatchingEventTypes' => $eventTypes,
                    'SnsDestination' => [
                        'TopicArn' => $topicArn,
                    ],
                ],
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
     * Check if a Configuration Set exists.
     *
     * @return array{success: bool, exists?: bool, error?: string}
     */
    public function getConfigurationSet(
        string $name,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->getConfigurationSet([
                'ConfigurationSetName' => $name,
            ]);

            return [
                'success' => true,
                'exists' => true,
            ];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return [
                    'success' => true,
                    'exists' => false,
                ];
            }

            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * All the event types that a configuration set can publish to the app.
     *
     * @return list<string>
     */
    public function getDefaultEventTypes(): array
    {
        return array_column(SesEventType::published(true, true), 'value');
    }

    /**
     * Get the event types based on tracking preferences.
     *
     * @return list<string>
     */
    public function getEventTypesForTracking(bool $openTracking, bool $clickTracking): array
    {
        return array_column(SesEventType::published($openTracking, $clickTracking), 'value');
    }

    /**
     * Fetch event destinations for a configuration set and extract tracking status.
     *
     * @return array{success: bool, open_tracking_enabled?: bool, click_tracking_enabled?: bool, error?: string}
     */
    public function fetchTrackingStatus(
        string $configSetName,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $response = $client->getConfigurationSetEventDestinations([
                'ConfigurationSetName' => $configSetName,
            ]);

            $eventTypes = collect($response['EventDestinations'] ?? [])
                ->flatMap(fn ($destination) => $destination['MatchingEventTypes'] ?? [])
                ->unique()
                ->values()
                ->all();

            return [
                'success' => true,
                'open_tracking_enabled' => in_array(SesEventType::Open->value, $eventTypes, true),
                'click_tracking_enabled' => in_array(SesEventType::Click->value, $eventTypes, true),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Enable each SNS event destination of a configuration set that is disabled.
     *
     * SES disables an SNS destination when it cannot publish to the topic, for
     * example while AWS suspends the account. SES does not enable it again, so
     * no events arrive until something enables it. The destination keeps its
     * event types and its topic.
     *
     * @return array{success: bool, enabled?: list<string>, error?: string}
     */
    public function enableDisabledSnsEventDestinations(
        string $configSetName,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $response = $client->getConfigurationSetEventDestinations([
                'ConfigurationSetName' => $configSetName,
            ]);

            $enabled = [];

            foreach ($response['EventDestinations'] ?? [] as $destination) {
                if (($destination['Enabled'] ?? false) || ! isset($destination['SnsDestination'])) {
                    continue;
                }

                $client->updateConfigurationSetEventDestination([
                    'ConfigurationSetName' => $configSetName,
                    'EventDestinationName' => $destination['Name'],
                    'EventDestination' => [
                        'Enabled' => true,
                        'MatchingEventTypes' => $destination['MatchingEventTypes'] ?? [],
                        'SnsDestination' => $destination['SnsDestination'],
                    ],
                ]);

                $enabled[] = (string) $destination['Name'];
            }

            return ['success' => true, 'enabled' => $enabled];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Set a custom redirect domain for open/click tracking.
     *
     * @return array{success: bool, error?: string}
     */
    public function putTrackingOptions(
        string $configSetName,
        string $customDomain,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->putConfigurationSetTrackingOptions([
                'ConfigurationSetName' => $configSetName,
                'CustomRedirectDomain' => $customDomain,
                'HttpsPolicy' => self::TRACKING_HTTPS_POLICY,
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
     * Remove the custom redirect domain from a configuration set.
     *
     * Uses the SES v1 DeleteConfigurationSetTrackingOptions API since
     * SES v2 has no equivalent delete operation.
     *
     * @return array{success: bool, error?: string}
     */
    public function removeTrackingOptions(
        string $configSetName,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createV1Client($accessKey, $secretKey, $region, $sessionToken);

            $client->deleteConfigurationSetTrackingOptions([
                'ConfigurationSetName' => $configSetName,
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
     * Delete a Configuration Set.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteConfigurationSet(
        string $name,
        string $accessKey,
        string $secretKey,
        string $region,
        ?string $sessionToken = null,
    ): array {
        try {
            $client = $this->createClient($accessKey, $secretKey, $region, $sessionToken);

            $client->deleteConfigurationSet([
                'ConfigurationSetName' => $name,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    private function createClient(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): SesV2Client
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

        if ($this->clientFactory) {
            return ($this->clientFactory)($config);
        }

        return new SesV2Client($config);
    }

    private function createV1Client(string $accessKey, string $secretKey, string $region, ?string $sessionToken = null): SesClient
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

        if ($this->v1ClientFactory) {
            return ($this->v1ClientFactory)($config);
        }

        return new SesClient($config);
    }
}
