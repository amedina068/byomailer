<?php

namespace App\Support;

class ContentSlot
{
    /**
     * Strip the `<div data-slot="content">…</div>` wrapper from a composed template HTML,
     * leaving only the wrapped slot content in place. Used to flatten the editor's slot
     * marker before the HTML is rendered to a recipient.
     */
    public static function unwrap(string $html): string
    {
        return (string) preg_replace(
            '/<div[^>]*data-slot=["\']content["\'][^>]*>([\s\S]*?)<\/div>/i',
            '$1',
            $html,
        );
    }

    /**
     * Extract the slot content from a composed template HTML. Returns the inner HTML
     * of the first `<div data-slot="content">…</div>` block, or an empty string if
     * no slot wrapper is found.
     */
    public static function extract(string $html): string
    {
        if (preg_match(
            '/<div[^>]*data-slot=["\']content["\'][^>]*>([\s\S]*?)<\/div>/i',
            $html,
            $matches,
        ) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * The template shell: the HTML without the first slot wrapper and its
     * content.
     */
    public static function withoutSlot(string $html): string
    {
        return (string) preg_replace(
            '/<div[^>]*data-slot=["\']content["\'][^>]*>[\s\S]*?<\/div>/i',
            '',
            $html,
            1,
        );
    }

    /**
     * Does the HTML contain a content slot wrapper?
     */
    public static function hasWrapper(string $html): bool
    {
        return preg_match('/<div[^>]*data-slot=["\']content["\'][^>]*>/i', $html) === 1;
    }

    /**
     * Compose template HTML with the given slot content, replacing the first
     * `{{ content }}` placeholder with a `<div data-slot="content">…</div>` wrapper.
     * The slot HTML is inserted literally, so text like `$70` or `\1` is never read as a backreference.
     */
    public static function hydrate(string $templateHtml, string $slotHtml): string
    {
        $safeSlot = trim($slotHtml) === '' ? '<p></p>' : $slotHtml;

        return (string) preg_replace_callback(
            '/\{\{\s*content\s*\}\}/',
            fn (): string => '<div data-slot="content">'.$safeSlot.'</div>',
            $templateHtml,
            1,
        );
    }
}
