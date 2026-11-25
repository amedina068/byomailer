import Link from '@tiptap/extension-link';

export const LINK_NAME_ATTRIBUTE = 'data-bm-link';

/**
 * Link mark with an optional `data-bm-link` name. At send time the server
 * changes the name to an SES link tag, so the reports and the automations
 * group the clicks by name.
 */
export const TrackedLink = Link.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            linkName: {
                default: null,
                parseHTML: (element: HTMLElement): string | null =>
                    element.getAttribute(LINK_NAME_ATTRIBUTE) || null,
                renderHTML: (attributes: Record<string, unknown>) =>
                    attributes.linkName
                        ? { [LINK_NAME_ATTRIBUTE]: attributes.linkName }
                        : {},
            },
        };
    },
});
