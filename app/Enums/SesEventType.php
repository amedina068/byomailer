<?php

namespace App\Enums;

/**
 * The SES event types that the app knows. The values are the names that the
 * configuration set API uses in `MatchingEventTypes`.
 */
enum SesEventType: string
{
    case Send = 'SEND';
    case Delivery = 'DELIVERY';
    case Open = 'OPEN';
    case Click = 'CLICK';
    case Bounce = 'BOUNCE';
    case Complaint = 'COMPLAINT';
    case Reject = 'REJECT';
    case RenderingFailure = 'RENDERING_FAILURE';
    case DeliveryDelay = 'DELIVERY_DELAY';

    /**
     * Read the `eventType` of an SES event. The event payload uses other names
     * than the API, for example "Rendering Failure" and "DeliveryDelay".
     * Both forms give the same case. An unknown name gives null.
     */
    public static function fromEventName(string $eventType): ?self
    {
        $apiName = preg_replace('/(?<=[a-z])(?=[A-Z])|\s+/', '_', trim($eventType));

        return self::tryFrom(strtoupper((string) $apiName));
    }

    /**
     * The event types that a configuration set publishes to the SNS topic.
     * Open and click events follow the tracking settings of the identity.
     *
     * @return list<self>
     */
    public static function published(bool $openTracking, bool $clickTracking): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => match ($type) {
                self::Open => $openTracking,
                self::Click => $clickTracking,
                self::DeliveryDelay => false,
                self::Send,
                self::Delivery,
                self::Bounce,
                self::Complaint,
                self::Reject,
                self::RenderingFailure => true,
            },
        ));
    }
}
