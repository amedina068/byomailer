<?php

namespace App\Enums;

/**
 * How an audience filter joins the filter before it.
 */
enum FilterConnector: string
{
    case And = 'and';
    case Or = 'or';

    /**
     * Read the connector of a filter. A missing or unknown connector reads
     * as "and", because "and" never makes the audience larger.
     */
    public static function read(mixed $connector): self
    {
        return (is_string($connector) ? self::tryFrom($connector) : null) ?? self::And;
    }
}
