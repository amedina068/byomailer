<?php

namespace App\Support;

class SnapsbrewOgImage
{
    /**
     * Build a signed snapsbrew render URL for the BYOMailer OG template,
     * overriding the per-surface text layers. Returns null when the
     * integration is not configured (e.g. local/CI without keys).
     */
    public static function url(string $eyebrow, string $headline): ?string
    {
        return self::signedUrl([
            ['layer' => 'eyebrow', 'text' => $eyebrow],
            ['layer' => 'headline', 'text' => $headline],
        ]);
    }

    /**
     * Build a signed snapsbrew render URL for an arbitrary set of layer
     * variant overrides. Signing follows the snapsbrew signed-URL spec:
     * HMAC-SHA256 of the alphabetically sorted query string.
     *
     * @param  array<int, array<string, string>>  $variants
     */
    public static function signedUrl(array $variants): ?string
    {
        $key = config('services.snapsbrew.signed_url_key');
        $templateId = config('services.snapsbrew.og_template_id');

        if (empty($key) || empty($templateId)) {
            return null;
        }

        $encoded = rtrim(strtr(base64_encode(json_encode($variants)), '+/', '-_'), '=');

        $fields = ['template_id' => $templateId, 'variants' => $encoded];
        ksort($fields);

        $query = http_build_query($fields);
        $signature = hash_hmac('sha256', $query, $key);
        $base = rtrim(config('services.snapsbrew.base_url'), '/');

        return $base.'/signed-url/image.jpg?'.$query.'&signature='.$signature;
    }
}
