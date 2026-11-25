import { Node } from '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        contentSlotBadge: {
            insertContentSlotBadge: () => ReturnType;
        };
    }
}

/**
 * Inline atom that renders `{{ content }}` as a styled pill inside the template editor.
 * Parses `<span data-slot="placeholder">` (TiptapEditor pre-processes literal `{{ content }}`
 * strings into this wrapper before loading content) and renders the same.
 */
export const ContentSlotBadge = Node.create({
    name: 'contentSlotBadge',

    inline: true,

    group: 'inline',

    atom: true,

    selectable: true,

    parseHTML() {
        return [
            {
                tag: 'span[data-slot="placeholder"]',
            },
        ];
    },

    renderHTML() {
        return [
            'span',
            {
                'data-slot': 'placeholder',
                class: 'tiptap-content-slot-badge',
            },
            '{{ content }}',
        ];
    },

    renderText() {
        return '{{ content }}';
    },

    addCommands() {
        return {
            insertContentSlotBadge:
                () =>
                ({ commands }) => {
                    return commands.insertContent({ type: this.name });
                },
        };
    },
});
