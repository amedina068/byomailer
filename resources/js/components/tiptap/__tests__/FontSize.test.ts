import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TYPOGRAPHY_EXTENSIONS, FONT_SIZES } from '../typography';

const buildEditor = (content = '<p>hello world</p>'): Editor =>
    new Editor({
        extensions: [StarterKit, ...TYPOGRAPHY_EXTENSIONS],
        content,
    });

describe('Tiptap typography extensions — font size', () => {
    let editor: Editor;

    beforeEach(() => {
        editor = buildEditor();
        editor.commands.selectAll();
    });

    afterEach(() => {
        editor.destroy();
    });

    it('applies inline font-size style on selection', () => {
        editor.chain().focus().setFontSize('18px').run();

        expect(editor.getHTML()).toContain('font-size: 18px');
    });

    it('removes font-size when unset', () => {
        editor.chain().focus().setFontSize('24px').run();
        editor.chain().focus().unsetFontSize().run();

        expect(editor.getHTML()).not.toContain('font-size');
    });

    it('exposes current size via getAttributes("textStyle")', () => {
        editor.chain().focus().setFontSize('32px').run();

        expect(editor.getAttributes('textStyle').fontSize).toBe('32px');
    });

    it('overwrites a previous size on re-apply', () => {
        editor.chain().focus().setFontSize('12px').run();
        editor.chain().focus().setFontSize('24px').run();

        const html = editor.getHTML();
        expect(html).toContain('font-size: 24px');
        expect(html).not.toContain('font-size: 12px');
    });

    it('parses font-size from existing inline HTML', () => {
        const e = buildEditor('<p><span style="font-size: 18px">x</span></p>');
        e.commands.selectAll();

        expect(e.getAttributes('textStyle').fontSize).toBe('18px');
        e.destroy();
    });

    it('FONT_SIZES exposes the locked named scale', () => {
        expect(FONT_SIZES).toEqual({
            xs: 12,
            s: 14,
            m: 16,
            l: 18,
            xl: 24,
            '2xl': 32,
        });
    });
});
