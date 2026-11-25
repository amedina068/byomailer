<?php

namespace App\Support;

/**
 * Joins a query string that follows a `{{ var }}` placeholder onto the value of the variable.
 *
 * Content like `{{ checkout_url }}?coupon=X` breaks when the value already has a query
 * string: the result has two `?` and the coupon becomes part of the last parameter.
 * The separator must be `&` when the value contains `?`, and `?` when it does not.
 */
class QueryStringJoiner
{
    public const SEPARATOR_SUFFIX = '__qs';

    /**
     * A placeholder followed by `?` and a `key=` pair. The `key=` lookahead keeps plain
     * prose like "Are you {{ name }}? Great" unchanged.
     */
    private const PLACEHOLDER_THEN_QUERY = '/\{\{\s*([\w.]+)\s*\}\}\?(?=[\w.~%\-\[\]]+=)/';

    public static function separatorFor(?string $value): string
    {
        return str_contains((string) $value, '?') ? '&' : '?';
    }

    /**
     * Rewrite `{{ var }}?key=` to `{{ var }}{{{ var__qs }}}key=` for a template that SES renders.
     * The separator uses triple braces so that SES does not HTML-escape the `&`.
     */
    public static function rewriteForTemplate(string $content): string
    {
        return preg_replace(
            self::PLACEHOLDER_THEN_QUERY,
            '{{ $1 }}{{{ $1'.self::SEPARATOR_SUFFIX.' }}}',
            $content,
        ) ?? $content;
    }

    /**
     * Add a `<key>__qs` separator for each value, for use with rewriteForTemplate().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withSeparators(array $data): array
    {
        foreach ($data as $key => $value) {
            if (str_ends_with((string) $key, self::SEPARATOR_SUFFIX) || ! (is_scalar($value) || $value === null)) {
                continue;
            }

            $data[$key.self::SEPARATOR_SUFFIX] = self::separatorFor((string) $value);
        }

        return $data;
    }

    /**
     * Replace one placeholder with its value. If a query string follows the placeholder,
     * join it with the correct separator.
     */
    public static function replacePlaceholder(string $content, string $key, string $value): string
    {
        $pattern = '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}(\?(?=[\w.~%\-\[\]]+=))?/';

        return preg_replace_callback(
            $pattern,
            fn (array $match): string => $value.(isset($match[1]) && $match[1] !== '' ? self::separatorFor($value) : ''),
            $content,
        ) ?? $content;
    }
}
