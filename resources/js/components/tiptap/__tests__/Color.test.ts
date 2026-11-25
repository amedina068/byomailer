import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import {
    TYPOGRAPHY_EXTENSIONS,
    isValidHexColor,
    normalizeHexColor,
} from '../typography';

describe('Tiptap typography extensions — color', () => {
    let editor: Editor;

    beforeEach(() => {
        editor = new Editor({
            extensions: [StarterKit, ...TYPOGRAPHY_EXTENSIONS],
            content: '<p>hi</p>',
        });
        editor.commands.selectAll();
    });

    afterEach(() => {
        editor.destroy();
    });

    it('applies inline color on selection', () => {
        editor.chain().focus().setColor('#c8ff00').run();

        expect(editor.getHTML()).toContain('color: #c8ff00');
    });

    it('unsets color', () => {
        editor.chain().focus().setColor('#ff0000').run();
        editor.chain().focus().unsetColor().run();

        expect(editor.getHTML()).not.toContain('color:');
    });

    it('exposes current color via getAttributes', () => {
        editor.chain().focus().setColor('#123abc').run();

        expect(editor.getAttributes('textStyle').color).toBe('#123abc');
    });
});

describe('hex color helpers', () => {
    it('isValidHexColor accepts #RRGGBB', () => {
        expect(isValidHexColor('#000000')).toBe(true);
        expect(isValidHexColor('#FFFFFF')).toBe(true);
        expect(isValidHexColor('#c8ff00')).toBe(true);
    });

    it('isValidHexColor rejects 3-digit, missing hash, or non-hex', () => {
        expect(isValidHexColor('#fff')).toBe(false);
        expect(isValidHexColor('c8ff00')).toBe(false);
        expect(isValidHexColor('#ZZZZZZ')).toBe(false);
        expect(isValidHexColor('')).toBe(false);
        expect(isValidHexColor('   ')).toBe(false);
    });

    it('normalizeHexColor adds missing hash and lowercases', () => {
        expect(normalizeHexColor('C8FF00')).toBe('#c8ff00');
        expect(normalizeHexColor('#C8FF00')).toBe('#c8ff00');
        expect(normalizeHexColor('  #abcdef  ')).toBe('#abcdef');
    });

    it('normalizeHexColor returns null for invalid', () => {
        expect(normalizeHexColor('#fff')).toBeNull();
        expect(normalizeHexColor('not-a-color')).toBeNull();
        expect(normalizeHexColor('')).toBeNull();
    });
});
