<?php

namespace App\Enums;

/**
 * How an audience filter combines its values. "all" needs each value,
 * "any" needs one value, and "none" excludes each value.
 */
enum FilterMode: string
{
    case All = 'all';
    case Any = 'any';
    case None = 'none';

    /**
     * The modes of a location filter. A contact has one country and one
     * timezone, so "all" has no use.
     *
     * @return list<self>
     */
    public static function forLocation(): array
    {
        return [self::Any, self::None];
    }
}
