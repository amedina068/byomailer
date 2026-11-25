<?php

namespace App\Rules;

use App\Support\ContentSlot;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The SafeHtml rule for a newsletter body that can hold a template.
 *
 * A template often puts `{{ content }}` in a paragraph. Then the composed body
 * has a `<div data-slot="content">` inside a `<p>`, which SafeHtml reads as
 * invalid HTML. So this rule checks the slot content and the template shell
 * around it one by one, each with every SafeHtml check.
 */
class SafeTemplatedHtml implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ContentSlot::hasWrapper($value)) {
            (new SafeHtml)->validate($attribute, $value, $fail);

            return;
        }

        (new SafeHtml)->validate($attribute, ContentSlot::extract($value), $fail);
        (new SafeHtml)->validate($attribute, ContentSlot::withoutSlot($value), $fail);
    }
}
