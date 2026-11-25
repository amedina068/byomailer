<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class NotInPast implements ValidationRule
{
    public function __construct(private int $toleranceSeconds = 60) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        try {
            $date = Carbon::parse((string) $value);
        } catch (Throwable) {
            return;
        }

        if ($date->lt(now()->subSeconds($this->toleranceSeconds))) {
            $fail('The :attribute must be a date at or after the current time.');
        }
    }
}
