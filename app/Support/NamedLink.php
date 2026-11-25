<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A named link is an anchor with a `data-bm-link` attribute. The name gives the
 * link an identity that does not depend on its URL. So the name stays the same
 * when template variables, UTM parameters or the destination change.
 *
 * At send time the attribute becomes an SES link tag. SES then returns the name
 * in `click.linkTags` of each Click event.
 */
class NamedLink
{
    public const ATTRIBUTE = 'data-bm-link';

    public const SES_TAG_KEY = 'bm_link';

    public const MAX_LENGTH = 100;

    private const ANCHOR_PATTERN = '/<a\s[^>]*>/i';

    private const ATTRIBUTE_PATTERN = '/\s'.self::ATTRIBUTE.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';

    /**
     * Convert a name to the form that SES accepts in a link tag: lowercase
     * letters, digits and dashes.
     */
    public static function normalize(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $slug = rtrim(Str::limit(Str::slug($name), self::MAX_LENGTH, ''), '-');

        return $slug === '' ? null : $slug;
    }

    /**
     * Replace the `data-bm-link` attribute of each anchor with an SES
     * `ses:tags` attribute. An attribute with no usable name is removed.
     */
    public static function toSesTags(string $html): string
    {
        if (stripos($html, self::ATTRIBUTE) === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::ANCHOR_PATTERN,
            fn (array $anchor): string => (string) preg_replace_callback(
                self::ATTRIBUTE_PATTERN,
                function (array $match): string {
                    $name = self::nameFromMatch($match);

                    return $name === null ? '' : ' ses:tags="'.self::SES_TAG_KEY.':'.$name.';"';
                },
                $anchor[0],
            ),
            $html,
        );
    }

    /**
     * @return list<string>
     */
    public static function namesIn(?string $html): array
    {
        if ($html === null || stripos($html, self::ATTRIBUTE) === false) {
            return [];
        }

        preg_match_all(self::ANCHOR_PATTERN, $html, $anchors);

        return collect($anchors[0])
            ->map(fn (string $anchor): ?string => preg_match(self::ATTRIBUTE_PATTERN, $anchor, $match) ? self::nameFromMatch($match) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Read the link name from the `click` object of an SES Click event.
     *
     * @param  array<string, mixed>  $click
     */
    public static function fromSesClick(array $click): ?string
    {
        $value = data_get($click, 'linkTags.'.self::SES_TAG_KEY);

        return self::normalize(is_array($value) ? ($value[0] ?? null) : $value);
    }

    /**
     * @param  array<int, string>  $match
     */
    private static function nameFromMatch(array $match): ?string
    {
        $raw = ($match[2] ?? '') !== '' ? $match[2] : $match[1];

        return self::normalize(html_entity_decode($raw));
    }
}
