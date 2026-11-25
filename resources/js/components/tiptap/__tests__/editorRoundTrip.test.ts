import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TYPOGRAPHY_EXTENSIONS, FONT_FAMILIES } from '../typography';
import { inlineElementDefaults } from '../elementDefaults';

const ARIAL = FONT_FAMILIES.find((f) => f.label === 'Arial')!.value!;
const GEORGIA = FONT_FAMILIES.find((f) => f.label === 'Georgia')!.value!;

const buildEditor = (content: string): Editor => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    return new Editor({
        element: el,
        extensions: [StarterKit, ...TYPOGRAPHY_EXTENSIONS],
        content,
    });
};

const serialize = (editor: Editor): string => inlineElementDefaults(editor.getHTML());

describe('editor → serialize round trip', () => {
    let editor: Editor;

    afterEach(() => {
        editor?.destroy();
        document.body.innerHTML = '';
    });

    it('a plain paragraph gains the per-element default styles on save', () => {
        editor = buildEditor('<p>plain text</p>');

        const out = serialize(editor);

        expect(out).toContain('plain text');
        expect(out).toMatch(/<p[^>]+style="[^"]*font-family: Arial[^"]*"/);
        expect(out).toMatch(/<p[^>]+style="[^"]*font-size: 16px[^"]*"/);
        expect(out).toMatch(/<p[^>]+style="[^"]*color: #1f2937[^"]*"/);
    });

    it('user-applied font-size on a span beats the paragraph default', () => {
        editor = buildEditor('<p>hello world</p>');
        editor.commands.selectAll();
        editor.chain().focus().setFontSize('24px').run();

        const out = serialize(editor);

        expect(out).toContain('font-size: 24px');
        expect(out).toMatch(/<p[^>]+style="[^"]*font-family: Arial/);
    });

    it('user-applied color on a span survives the inliner unmodified', () => {
        editor = buildEditor('<p>hello</p>');
        editor.commands.selectAll();
        editor.chain().focus().setColor('#c8ff00').run();

        const out = serialize(editor);

        expect(out).toContain('color: #c8ff00');
    });

    it('user-applied font-family on a heading overrides the heading default', () => {
        editor = buildEditor('<h1>title</h1>');
        editor.commands.selectAll();
        editor.chain().focus().setFontFamily(ARIAL).run();

        const out = serialize(editor);

        // The user picked Arial — the inline mark should be present.
        expect(out).toContain('Arial');
        // The h1 element still gets its default font-size + color since user did
        // not override them.
        expect(out).toMatch(/<h1[^>]+style="[^"]*font-size: 32px/);
        expect(out).toMatch(/<h1[^>]+style="[^"]*color: #111827/);
    });

    it('h1 + p round trip independently apply correct defaults', () => {
        editor = buildEditor('<h1>Title</h1><p>Body</p>');

        const out = serialize(editor);

        expect(out).toMatch(/<h1[^>]+style="[^"]*font-family: Georgia/);
        expect(out).toMatch(/<h1[^>]+style="[^"]*font-size: 32px/);
        expect(out).toMatch(/<p[^>]+style="[^"]*font-family: Arial/);
        expect(out).toMatch(/<p[^>]+style="[^"]*font-size: 16px/);
    });

    it('a list (ul + li) gains the list default font/color/size', () => {
        editor = buildEditor('<ul><li><p>one</p></li><li><p>two</p></li></ul>');

        const out = serialize(editor);

        expect(out).toMatch(/<ul[^>]+style="[^"]*font-family: Arial/);
        expect(out).toMatch(/<ul[^>]+style="[^"]*font-size: 16px/);
        expect(out).toContain('one');
        expect(out).toContain('two');
    });

    it('serialization is idempotent — second pass produces identical output', () => {
        editor = buildEditor('<h2>Sub</h2><p>Body <span style="color: #c8ff00">accent</span></p>');
        editor.commands.selectAll();
        editor.chain().focus().setFontFamily(GEORGIA).run();

        const first = serialize(editor);
        const second = inlineElementDefaults(first);

        expect(second).toBe(first);
    });

    it('blockquote gets its own muted color default, not the body color', () => {
        editor = buildEditor('<blockquote><p>quote</p></blockquote>');

        const out = serialize(editor);

        expect(out).toMatch(/<blockquote[^>]+style="[^"]*color: #4b5563/);
        expect(out).toMatch(/<blockquote[^>]+style="[^"]*font-family: Georgia/);
    });

    it('produces inline styles only — no class-based typography on block elements', () => {
        editor = buildEditor('<h1>x</h1><p>y</p><ul><li><p>z</p></li></ul>');

        const out = serialize(editor);

        // None of these blocks should rely on classes for typography.
        expect(out).not.toMatch(/<h1[^>]*class="[^"]*font/);
        expect(out).not.toMatch(/<p[^>]*class="[^"]*font/);
        expect(out).not.toMatch(/<ul[^>]*class="[^"]*font/);
    });
});
