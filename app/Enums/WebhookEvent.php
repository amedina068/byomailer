<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case ContactSubscribed = 'contact.subscribed';
    case ContactUnsubscribed = 'contact.unsubscribed';
    case ContactResubscribed = 'contact.resubscribed';
    case NewsletterSent = 'newsletter.sent';
    case SequenceStepSent = 'sequence_step.sent';
    case SequenceCompleted = 'sequence.completed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::ContactSubscribed => 'Contact subscribed',
            self::ContactUnsubscribed => 'Contact unsubscribed',
            self::ContactResubscribed => 'Contact resubscribed',
            self::NewsletterSent => 'Newsletter sent',
            self::SequenceStepSent => 'Sequence step sent',
            self::SequenceCompleted => 'Sequence completed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ContactSubscribed => 'Fires when a contact opts in to a list.',
            self::ContactUnsubscribed => 'Fires when a contact unsubscribes.',
            self::ContactResubscribed => 'Fires when a previously unsubscribed contact opts back in.',
            self::NewsletterSent => 'Fires once when a newsletter finishes sending. For an A/B test, it fires when the winner is picked.',
            self::SequenceStepSent => 'Fires when a sequence step is sent to a contact.',
            self::SequenceCompleted => 'Fires when a contact finishes a sequence.',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::ContactSubscribed,
            self::ContactUnsubscribed,
            self::ContactResubscribed => 'contact',
            self::NewsletterSent => 'newsletter',
            self::SequenceStepSent,
            self::SequenceCompleted => 'sequence',
        };
    }
}
