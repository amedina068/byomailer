<?php

namespace App\Rules;

use App\Support\Liquid;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Email content must be valid Liquid. Plain `{{ name }}` content is always
 * valid, so content from before Liquid saves the same as before.
 */
class ValidLiquid implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($error = Liquid::error($value)) !== null) {
            $fail($error);
        }
    }
}
