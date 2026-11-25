<?php

namespace App\Enums;

/**
 * The variant of an A/B test newsletter. The `variant` column of a message
 * and the `ab_test_winner_variant` column of a newsletter store the value.
 * A message of a newsletter without an A/B test has no variant.
 */
enum AbVariant: string
{
    case A = 'a';
    case B = 'b';

    /**
     * The suffix of the request fields and the error keys of the variant:
     * "subject" for A and "subject_b" for B.
     */
    public function fieldSuffix(): string
    {
        return match ($this) {
            self::A => '',
            self::B => '_b',
        };
    }
}
