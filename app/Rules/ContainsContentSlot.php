<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ContainsContentSlot implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $count = preg_match_all('/\{\{\s*content\s*\}\}/', $value);

        if ($count === 0) {
            $fail('The template must include a {{ content }} placeholder where the newsletter or step content will go.');

            return;
        }

        if ($count > 1) {
            $fail('The template can only include one {{ content }} placeholder.');
        }
    }
}
