<?php

namespace App\Enums;

enum NewsletterStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case AbTesting = 'ab_testing';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::AbTesting => 'A/B testing',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    /**
     * The value and the label of each status, for the newsletter pages.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }

    /**
     * The newsletter has not started to send, so its content, audience and
     * schedule can change. Any other status is locked.
     */
    public function isMutable(): bool
    {
        return in_array($this, self::mutable(), true);
    }

    /**
     * The statuses of a newsletter that has not started to send.
     *
     * @return list<self>
     */
    public static function mutable(): array
    {
        return [self::Draft, self::Scheduled];
    }

    /**
     * The statuses of a newsletter that can still send. A sent or a failed
     * newsletter does not send again.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Draft, self::Scheduled, self::Queued, self::AbTesting];
    }

    /**
     * The statuses of a newsletter that has a send time or sends now. A
     * draft does not send until a user schedules it.
     *
     * @return list<self>
     */
    public static function scheduledOrSending(): array
    {
        return [self::Scheduled, self::Queued, self::AbTesting];
    }
}
