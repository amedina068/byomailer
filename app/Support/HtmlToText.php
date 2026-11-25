<?php

namespace App\Support;

use Soundasleep\Html2Text;

/**
 * Single conversion point from email HTML to the `text/plain` alternative part.
 *
 * Every send path must use this. `strip_tags` was used in some places and it
 * throws away link URLs and block structure, which produces an empty-looking
 * plain part — a spam signal and a broken experience for text-only clients.
 */
class HtmlToText
{
    /**
     * Convert email HTML to plain text.
     *
     * `drop_links => false` keeps the destination URLs in the text part.
     * `ignore_errors => true` stops one malformed tag from the editor from
     * failing a whole send.
     */
    public static function convert(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        return Html2Text::convert($html, [
            'ignore_errors' => true,
            'drop_links' => false,
        ]);
    }
}
