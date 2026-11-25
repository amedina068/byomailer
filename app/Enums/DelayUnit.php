<?php

namespace App\Enums;

/**
 * The unit of the delay of a sequence step. The `delay_days` column holds the
 * number, and the `delay_type` column holds this unit.
 */
enum DelayUnit: string
{
    case Days = 'days';
    case Hours = 'hours';

    /**
     * The largest delay that a step can have in this unit: one year.
     */
    public function maxDelay(): int
    {
        return match ($this) {
            self::Days => 365,
            self::Hours => 8760,
        };
    }

    /**
     * The largest delay for the unit that a request sends. An unknown unit gets
     * the days limit. The unit rule of the request reports the unknown unit.
     */
    public static function maxDelayFor(mixed $unit): int
    {
        $delayUnit = is_string($unit) ? self::tryFrom($unit) : null;

        return ($delayUnit ?? self::Days)->maxDelay();
    }

    public function toHours(int $delay): int
    {
        return match ($this) {
            self::Days => $delay * 24,
            self::Hours => $delay,
        };
    }
}
