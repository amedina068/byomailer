import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TYPOGRAPHY_EXTENSIONS, FONT_FAMILIES } from '../typography';

const ARIAL = FONT_FAMILIES.find((f) => f.label === 'Arial')!.value!;
const GEORGIA = FONT_FAMILIES.find((f) => f.label === 'Georgia')!.value!;

describe('Tiptap typography extensions — font family', () => {
    let editor: Editor;

    beforeEach(() => {
        editor = new Editor({
            extensions: [StarterKit, ...TYPOGRAPHY_EXTENSIONS],
            content: '<p>hello</p>',
        });
        editor.commands.selectAll();
    });

    afterEach(() => {
        editor.destroy();
    });

    it('applies inline font-family on selection', () => {
        editor.chain().focus().setFontFamily(ARIAL).run();

        expect(editor.getHTML()).toContain('font-family');
        expect(editor.getHTML()).toContain('Arial');
    });

    it('reflects current family via getAttributes', () => {
        editor.chain().focus().setFontFamily(GEORGIA).run();

        expect(editor.getAttributes('textStyle').fontFamily).toContain('Georgia');
    });

    it('unsets font-family', () => {
        editor.chain().focus().setFontFamily(ARIAL).run();
        editor.chain().focus().unsetFontFamily().run();

        expect(editor.getHTML()).not.toContain('font-family');
    });

    it('exposes 9 email-safe families plus Default', () => {
        expect(FONT_FAMILIES).toHaveLength(10);
        expect(FONT_FAMILIES[0]).toEqual({ label: 'Default', value: null });

        const labels = FONT_FAMILIES.slice(1).map((f) => f.label);
        expect(labels).toEqual([
            'System UI',
            'Arial',
            'Helvetica',
            'Georgia',
            'Times New Roman',
            'Verdana',
            'Tahoma',
            'Courier New',
            'JetBrains Mono',
        ]);
    });

    it('every non-default family value uses an email-safe stack with fallback', () => {
        for (const family of FONT_FAMILIES.slice(1)) {
            expect(family.value).toMatch(/,\s*\w/);
        }
    });
});
