<?php

namespace App\Support;

use Illuminate\Support\Str;

class MarketingOgImage
{
    /**
     * Per-surface OG card copy keyed by route name: [eyebrow, headline].
     * Docs guides and REST API pages are resolved by section prefix instead
     * (see variantsFor) so every page gets its own snap without an entry each.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SURFACES = [
        'documentation' => [
            'DOCUMENTATION',
            'Everything you need to ship email',
        ],
    ];

    /**
     * Slug fragments that must keep a fixed casing when humanized into a headline.
     *
     * @var array<string, string>
     */
    private const ACRONYMS = [
        'aws' => 'AWS',
        'iam' => 'IAM',
        'ses' => 'SES',
        'api' => 'API',
        'mcp' => 'MCP',
        'dkim' => 'DKIM',
        'dns' => 'DNS',
    ];

    /**
     * Resolve the signed OG image URL for a route name, or null when the route
     * has no marketing surface mapping or the integration is unconfigured.
     */
    public static function forRoute(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $variants = self::variantsFor($name);

        if ($variants === null) {
            return null;
        }

        return SnapsbrewOgImage::url($variants[0], $variants[1]);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function variantsFor(string $name): ?array
    {
        if (isset(self::SURFACES[$name])) {
            return self::SURFACES[$name];
        }

        if (str_starts_with($name, 'documentation.guides.')) {
            $slug = substr($name, strlen('documentation.guides.'));

            return ['DOCS · GUIDE', self::humanize($slug)];
        }

        if (str_starts_with($name, 'documentation.api.')) {
            $slug = substr($name, strlen('documentation.api.'));

            if ($slug === 'index') {
                return ['DOCS · REST API', 'BYOMailer REST API'];
            }

            return ['DOCS · REST API', self::humanize($slug).' API reference'];
        }

        return null;
    }

    private static function humanize(string $slug): string
    {
        $words = array_map(
            fn (string $word): string => self::ACRONYMS[$word] ?? Str::ucfirst($word),
            explode('-', $slug)
        );

        return implode(' ', $words);
    }
}
