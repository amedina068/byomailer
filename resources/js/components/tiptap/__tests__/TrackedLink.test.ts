import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { afterEach, describe, expect, it } from 'vitest';

import { inlineElementDefaults } from '../elementDefaults';
import { TrackedLink } from '../TrackedLink';

const buildEditor = (content: string): Editor =>
    new Editor({
        extensions: [
            StarterKit.configure({ link: false }),
            TrackedLink.configure({ openOnClick: false }),
        ],
        content,
    });

describe('TrackedLink data-bm-link attribute', () => {
    let editor: Editor;

    afterEach(() => {
        editor?.destroy();
    });

    it('keeps data-bm-link from parse to render', () => {
        editor = buildEditor(
            '<p><a href="https://example.com/sale" data-bm-link="black-friday">Shop</a></p>',
        );

        const html = inlineElementDefaults(editor.getHTML());

        expect(html).toMatch(/<a[^>]+data-bm-link="black-friday"/);
        expect(html).toContain('href="https://example.com/sale"');
    });

    it('exposes the name through getAttributes("link")', () => {
        editor = buildEditor(
            '<p><a href="https://example.com" data-bm-link="hero-cta">Go</a></p>',
        );
        editor.commands.setTextSelection(2);

        expect(editor.getAttributes('link').linkName).toBe('hero-cta');
    });

    it('renders no attribute for a link without a name', () => {
        editor = buildEditor('<p><a href="https://example.com">Go</a></p>');

        expect(editor.getHTML()).not.toContain('data-bm-link');
    });

    it('sets, changes and removes the name with the editor commands', () => {
        editor = buildEditor('<p>Shop now</p>');
        editor.commands.selectAll();

        const applyLink = (linkName: string | null): void => {
            editor
                .chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href: 'https://example.com' })
                .updateAttributes('link', { linkName })
                .run();
        };

        applyLink('spring-sale');
        expect(editor.getHTML()).toContain('data-bm-link="spring-sale"');

        editor.commands.setTextSelection(2);
        applyLink('summer-sale');
        expect(editor.getHTML()).toContain('data-bm-link="summer-sale"');
        expect(editor.getHTML()).not.toContain('spring-sale');

        editor.commands.setTextSelection(2);
        applyLink(null);
        expect(editor.getHTML()).not.toContain('data-bm-link');
        expect(editor.getHTML()).toContain('href="https://example.com"');
    });
});
