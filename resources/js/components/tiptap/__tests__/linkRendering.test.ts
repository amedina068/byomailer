import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { ELEMENT_DEFAULTS } from '../elementDefaults';

const buildEditor = (content: string): Editor => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    return new Editor({
        element: el,
        extensions: [
            StarterKit,
            Link.configure({
                openOnClick: false,
                HTMLAttributes: {
                    style: `color: ${ELEMENT_DEFAULTS.a.color}; text-decoration: ${ELEMENT_DEFAULTS.a.textDecoration}`,
                },
            }),
        ],
        content,
    });
};

describe('editor link rendering', () => {
    let editor: Editor;

    afterEach(() => {
        editor?.destroy();
        document.body.innerHTML = '';
    });

    it('renders parsed <a> with the email-blue underline inline style', () => {
        editor = buildEditor('<p><a href="https://x.com">Click</a></p>');

        const html = editor.view.dom.outerHTML;
        expect(html).toMatch(/<a[^>]+style="[^"]*color: #2563eb[^"]*"/);
        expect(html).toMatch(/<a[^>]+style="[^"]*text-decoration: underline[^"]*"/);
    });

    it('also paints links that came from a template-style HTML even if their original style was dropped', () => {
        // Tiptap's Link schema does not preserve `style`; the editor's HTMLAttributes
        // forces our fallback so rendered links still have visible link styling.
        editor = buildEditor(
            '<p><a href="x" style="color: #ffffff;text-decoration: none">x</a></p>',
        );

        const html = editor.view.dom.outerHTML;
        expect(html).toMatch(/<a[^>]+style="[^"]*color: #2563eb[^"]*"/);
    });

    it('does not change the saved model HTML for the href', () => {
        editor = buildEditor('<p><a href="https://x.com">y</a></p>');

        expect(editor.getHTML()).toContain('href="https://x.com"');
    });
});
