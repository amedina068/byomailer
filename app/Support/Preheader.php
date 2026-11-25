<?php

namespace App\Support;

class Preheader
{
    /**
     * Inline style for the hidden preheader div.
     *
     * `color:transparent` (not white) so the div stays invisible even if a
     * future client ignores `display:none`. `mso-hide:all` covers Outlook.
     */
    private const STYLE = 'display:none;font-size:1px;color:transparent;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;';

    /**
     * Spacer string appended after preview text to push body content out of
     * the inbox snippet area in Gmail / Apple Mail. Zero-width-non-joiner +
     * non-breaking-space pairs are invisible but consume snippet characters.
     */
    private const SPACER = '&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;';

    /**
     * Render the hidden preheader HTML. Returns empty string when blank so
     * callers can prepend it unconditionally.
     *
     * The body argument may contain raw `{{ var }}` placeholders (for SES
     * handlebars substitution) — they survive `htmlspecialchars` because
     * braces are not HTML-special.
     */
    public static function html(?string $previewText): string
    {
        $trimmed = trim((string) $previewText);

        if ($trimmed === '') {
            return '';
        }

        $escaped = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');

        return '<div style="'.self::STYLE.'">'.$escaped.self::SPACER.'</div>';
    }
}
