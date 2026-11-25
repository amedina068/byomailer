<?php

namespace App\Concerns;

use App\Support\QueryStringJoiner;
use DOMDocument;

trait AppliesUtmParameters
{
    /**
     * The key suffix of the copy of a variable value that carries the UTM parameters.
     */
    public const UTM_VALUE_SUFFIX = '__utm';

    /**
     * Variables that BYOMailer fills with its own links or text. They never get UTM parameters.
     *
     * @var list<string>
     */
    public const SYSTEM_TEMPLATE_VARIABLES = [
        'content',
        'unsubscribe_url',
        'one_click_unsubscribe_url',
        'preferences_url',
        'opt_in_url',
        'postal_address',
    ];

    /**
     * @param  array<string, string>  $utmParams
     */
    protected static function addUtmParametersToHtml(string $content, array $utmParams): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<html><head><meta charset="UTF-8"/></head><body>'.$content.'</body></html>',
            LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $content;
        }

        $links = $document->getElementsByTagName('a');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            if (! $href) {
                continue;
            }

            $link->setAttribute('href', self::addUtmParametersToUrl($href, $utmParams));
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $content;
        }

        $result = '';

        foreach ($body->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        // saveHTML() encodes & as &amp; in URI attributes (correct per HTML spec),
        // but URL analytics tools often don't decode HTML entities. Decode only within
        // URI attributes. Also restore `{{ var }}` handlebars placeholders that
        // DOMDocument URL-encodes — depending on the libxml version it encodes only the
        // spaces (`{{%20var%20}}`) or the braces too (`%7B%7B%20var%20%7D%7D`). This
        // covers every `href` and `src` in the full body, not just template links, so
        // downstream templating engines (SES, Mailgun, etc.) parse them.
        $result = preg_replace_callback(
            '/\b(href|src)="([^"]+)"/',
            function ($m) {
                $value = str_replace('&amp;', '&', $m[2]);

                $value = preg_replace_callback(
                    '/(?:\{\{|%7B%7B)(.*?)(?:\}\}|%7D%7D)/i',
                    fn ($h) => '{{'.urldecode($h[1]).'}}',
                    $value,
                ) ?? $value;

                return $m[1].'="'.$value.'"';
            },
            $result,
        ) ?? $result;

        return $result ?: $content;
    }

    /**
     * @param  array<string, string>  $utmParams
     */
    protected static function addUtmParametersToPlainText(string $content, array $utmParams): string
    {
        return (string) preg_replace_callback(
            '#https?://[^\s<>\"]+#',
            function (array $match) use ($utmParams): string {
                $url = $match[0];
                $trailing = '';

                if (preg_match('/([.,;:!?)]+)$/', $url, $punctuation)) {
                    $trailing = $punctuation[1];
                    $url = substr($url, 0, -strlen($trailing));
                }

                return self::addUtmParametersToUrl($url, $utmParams).$trailing;
            },
            $content,
        ) ?? $content;
    }

    /**
     * @param  array<string, string>  $utmParams
     */
    private static function addUtmParametersToUrl(string $url, array $utmParams): string
    {
        if ($url === '') {
            return $url;
        }

        // Skip template variable placeholders — they are resolved after UTM injection
        // and must never receive UTM params (e.g. {{unsubscribe_url}}). Catch the
        // URL-encoded brace form too, in case stored content already carries it.
        if (str_contains($url, '{{') || stripos($url, '%7B%7B') !== false) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? '';

        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $url;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#^/(preferences|unsubscribe|one-click-unsubscribe|resubscribe|opt-in)(/|$)#', $path) === 1) {
            return $url;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        // A signed URL is valid only with its exact query string. A new parameter
        // breaks the signature, so the link stays as it is.
        if (array_key_exists('signature', $query)) {
            return $url;
        }

        // Merge defaults first so existing params always take precedence,
        // but missing UTM keys are still filled in individually.
        $parts['query'] = http_build_query(array_merge($utmParams, $query));

        return self::buildUrlFromParts($parts);
    }

    /**
     * Point each link whose href starts with a `{{ var }}` placeholder at the `<var>__utm`
     * copy of the value, for a template that SES renders. SES fills the placeholders after
     * BYOMailer adds the UTM parameters, so the value must carry them. A query string after
     * the placeholder is joined with the `<var>__utm__qs` separator. Use
     * withUtmValues() to add both keys to the template data.
     *
     * These links stay as they are: system variables, text after the placeholder that is
     * not a query string or a fragment, and a query string that sets a utm_ parameter.
     */
    protected static function pointVariableLinksAtUtmValues(string $content): string
    {
        return preg_replace_callback(
            '/(?<![\w-])href="\{\{\s*([\w.]+)\s*\}\}(\?[^"#]*)?(#[^"]*)?"/',
            function (array $match): string {
                $variable = $match[1];
                $query = ltrim($match[2] ?? '', '?');
                $fragment = $match[3] ?? '';

                if (in_array($variable, self::SYSTEM_TEMPLATE_VARIABLES, true) || str_ends_with($variable, self::UTM_VALUE_SUFFIX)) {
                    return $match[0];
                }

                $queryParameters = [];
                parse_str(str_replace('&amp;', '&', $query), $queryParameters);

                foreach (array_keys($queryParameters) as $key) {
                    if (str_starts_with(strtolower((string) $key), 'utm_')) {
                        return $match[0];
                    }
                }

                $key = $variable.self::UTM_VALUE_SUFFIX;
                $href = '{{{ '.$key.' }}}';

                if ($query !== '') {
                    $href .= '{{{ '.$key.QueryStringJoiner::SEPARATOR_SUFFIX.' }}}'.$query;
                }

                return 'href="'.$href.$fragment.'"';
            },
            $content,
        ) ?? $content;
    }

    /**
     * Add a `<key>__utm` copy of each value for the links that pointVariableLinksAtUtmValues()
     * rewrites. A value that is a URL gets the UTM parameters with the same rules as a fixed
     * link. The template prints the copy without HTML escaping, so the characters that can
     * end an attribute are URL-encoded.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $utmParams
     * @return array<string, mixed>
     */
    protected static function withUtmValues(array $data, array $utmParams): array
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (! (is_scalar($value) || $value === null)
                || in_array($key, self::SYSTEM_TEMPLATE_VARIABLES, true)
                || str_ends_with($key, self::UTM_VALUE_SUFFIX)
                || str_ends_with($key, QueryStringJoiner::SEPARATOR_SUFFIX)) {
                continue;
            }

            $url = $utmParams === []
                ? trim((string) $value)
                : self::addUtmParametersToUrl(trim((string) $value), $utmParams);

            $data[$key.self::UTM_VALUE_SUFFIX] = str_replace(
                ['"', "'", '<', '>', ' '],
                ['%22', '%27', '%3C', '%3E', '%20'],
                $url,
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function buildUrlFromParts(array $parts): string
    {
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        $user = $parts['user'] ?? null;
        $pass = $parts['pass'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        $authority = '';

        if ($host) {
            $authority = $host;

            if ($user) {
                $authority = $user.($pass ? ':'.$pass : '').'@'.$authority;
            }

            if ($port) {
                $authority .= ':'.$port;
            }
        }

        $url = ($scheme ? $scheme.'://' : '').$authority.$path;

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        if ($fragment !== null && $fragment !== '') {
            $url .= '#'.$fragment;
        }

        return $url;
    }
}
