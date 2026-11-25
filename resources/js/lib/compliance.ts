/**
 * The same pattern as `SendableContentCheck::UNSUBSCRIBE_PATTERN` on the server.
 */
const UNSUBSCRIBE_PATTERN = /\{\{\s*unsubscribe_url\s*\}\}/;

/**
 * Does one of the sources contain the `{{ unsubscribe_url }}` placeholder?
 * Pass the composed HTML and, when it exists, the plain-text body.
 */
export function hasUnsubscribeLink(
    ...sources: Array<string | null | undefined>
): boolean {
    return sources.some(
        (source) =>
            typeof source === 'string' && UNSUBSCRIBE_PATTERN.test(source),
    );
}

/**
 * Is the body blank? A body with an image is not blank. The server reports a
 * blank body as a problem that blocks the send.
 */
function isBlankBody(html: string, text: string): boolean {
    const visibleText = html
        .replace(/<(style|script)[\s\S]*?<\/\1>/gi, '')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;|\u00a0/g, ' ')
        .trim();

    return visibleText === '' && !/<img\b/i.test(html) && text.trim() === '';
}

/**
 * Show the unsubscribe warning for this body? Not for a blank body, and not
 * for a body with the `{{ unsubscribe_url }}` placeholder. This is the same
 * rule as `SendableContentCheck::warnings()` on the server.
 */
export function needsUnsubscribeWarning(
    html: string | null | undefined,
    text?: string | null,
): boolean {
    const safeHtml = html ?? '';
    const safeText = text ?? '';

    return (
        !isBlankBody(safeHtml, safeText) &&
        !hasUnsubscribeLink(safeHtml, safeText)
    );
}
