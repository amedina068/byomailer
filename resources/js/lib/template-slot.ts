export const CONTENT_PLACEHOLDER = '{{ content }}';

/**
 * Split a template's HTML at its `{{ content }}` placeholder. Used by the
 * sandwich-style editor surface that renders the template wrapper as static
 * iframes above and below the live editor. Returns the original HTML in
 * `before` and an empty `after` when no placeholder is present.
 */
export function splitTemplateAtSlot(templateHtml: string): {
    before: string;
    after: string;
    hasSlot: boolean;
} {
    const match = templateHtml.match(/\{\{\s*content\s*\}\}/);
    if (!match || match.index === undefined) {
        return { before: templateHtml, after: '', hasSlot: false };
    }

    return {
        before: templateHtml.slice(0, match.index),
        after: templateHtml.slice(match.index + match[0].length),
        hasSlot: true,
    };
}

/**
 * Does the template HTML contain a content placeholder?
 */
export function hasContentPlaceholder(html: string): boolean {
    return /\{\{\s*content\s*\}\}/.test(html);
}

/**
 * How many content placeholders does the HTML contain?
 */
export function countContentPlaceholders(html: string): number {
    const matches = html.match(/\{\{\s*content\s*\}\}/g);

    return matches ? matches.length : 0;
}

/**
 * Replace {{ content }} in a template with a styled `<div data-slot="content">` wrapper
 * containing the given slot HTML. Used when applying a template to a newsletter/step.
 */
export function hydrateTemplate(
    templateHtml: string,
    slotHtml: string,
): string {
    const safeSlot = slotHtml && slotHtml.trim() !== '' ? slotHtml : '<p></p>';

    // A function replacer inserts the slot literally, so `$&` or `$$` in the content is not a replacement pattern.
    return templateHtml.replace(
        /\{\{\s*content\s*\}\}/,
        () => `<div data-slot="content">${safeSlot}</div>`,
    );
}

/**
 * Given a composed HTML (template with a `<div data-slot="content">...</div>` slot),
 * extract the slot's inner HTML and return both the slot content and a flattened
 * composed HTML where the slot div is replaced by its inner HTML.
 */
export function extractSlot(composedHtml: string): {
    slotContent: string;
    composedHtml: string;
} {
    const match = composedHtml.match(
        /<div[^>]*data-slot=["']content["'][^>]*>([\s\S]*?)<\/div>/i,
    );

    if (!match) {
        return { slotContent: '', composedHtml };
    }

    const slotContent = match[1].trim();
    const flattened = composedHtml.replace(match[0], () => slotContent);

    return { slotContent, composedHtml: flattened };
}

/**
 * Convert literal `{{ content }}` text to a styled placeholder span used by the
 * ContentSlotBadge TipTap node. Only used in the template editor.
 */
export function wrapPlaceholderForEditor(html: string): string {
    return html.replace(
        /\{\{\s*content\s*\}\}/g,
        '<span data-slot="placeholder">{{ content }}</span>',
    );
}

/**
 * Reverse `wrapPlaceholderForEditor` — strip the placeholder span wrapper so the
 * template is stored with a literal `{{ content }}` string in the database.
 */
export function unwrapPlaceholderForStorage(html: string): string {
    return html.replace(
        /<span[^>]*data-slot=["']placeholder["'][^>]*>\s*\{\{\s*content\s*\}\}\s*<\/span>/gi,
        '{{ content }}',
    );
}
