<?php

namespace App\Support\Liquid;

use Keepsuit\Liquid\Filters\FiltersProvider;

/**
 * The last filter of each `{{ }}` output. `EscapeOutput` adds it.
 *
 * A list prints as JSON, the same as the `{{ variable }}` replacement before
 * Liquid. The HTML filter escapes the value, so contact data cannot add markup.
 */
class OutputFilters extends FiltersProvider
{
    public function bmText(mixed $value): mixed
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    public function bmHtml(mixed $value): string
    {
        $value = $this->bmText($value);

        return e(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        });
    }
}
