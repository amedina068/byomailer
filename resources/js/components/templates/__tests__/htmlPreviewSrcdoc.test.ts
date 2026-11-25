import { describe, expect, it } from 'vitest';
import { buildPreviewSrcdoc } from '../htmlPreviewSrcdoc';

describe('buildPreviewSrcdoc', () => {
    it('wraps HTML in a full document with white background and preserves text', () => {
        const out = buildPreviewSrcdoc('<p>hi</p>');
        expect(out).toContain('<!doctype html>');
        expect(out).toContain('<body');
        expect(out).toContain('hi');
    });

    it('imposes the same body font-family fallback as the editor (Arial)', () => {
        const out = buildPreviewSrcdoc('<p>x</p>');
        expect(out).toMatch(/body\s*{[^}]*font-family:\s*Arial/);
    });

    it('imposes the same body font-size fallback as the editor (16px)', () => {
        const out = buildPreviewSrcdoc('<p>x</p>');
        expect(out).toMatch(/body\s*{[^}]*font-size:\s*16px/);
    });

    it('imposes the same body color fallback as the editor (#1f2937)', () => {
        const out = buildPreviewSrcdoc('<p>x</p>');
        expect(out).toMatch(/body\s*{[^}]*color:\s*#1f2937/);
    });

    it('applies the link default color via the body CSS rule', () => {
        const out = buildPreviewSrcdoc('<a href="#">x</a>');
        expect(out).toMatch(/body a\s*{[^}]*color:\s*#2563eb/);
        expect(out).toMatch(/body a\s*{[^}]*text-decoration:\s*underline/);
    });

    it('keeps an image safety reset', () => {
        const out = buildPreviewSrcdoc('');
        expect(out).toMatch(/img\s*{[^}]*max-width/);
    });

    it('uses 16px body padding to match the editor wrapper (p-4)', () => {
        const out = buildPreviewSrcdoc('');
        expect(out).toMatch(/body\s*{[^}]*padding:\s*16px/);
    });

    it('inlines element defaults so legacy HTML without inline styles still renders', () => {
        const out = buildPreviewSrcdoc('<p>plain</p>');
        // Either via CSS rules in <style> OR inlined onto the element itself.
        expect(out).toMatch(/font-family:\s*Arial/);
    });

    it('opens links in a new tab via <base target="_blank">', () => {
        const out = buildPreviewSrcdoc('');
        expect(out).toContain('<base target="_blank">');
    });

    it('treats empty/null content as empty body', () => {
        const out = buildPreviewSrcdoc('');
        expect(out).toContain('<body');
    });
});
