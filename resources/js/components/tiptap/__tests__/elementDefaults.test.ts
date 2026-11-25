import { describe, expect, it } from 'vitest';
import {
    ELEMENT_DEFAULTS,
    defaultsToCss,
    inlineElementDefaults,
} from '../elementDefaults';

describe('ELEMENT_DEFAULTS map', () => {
    it('covers h1, h2, h3, p, ul, ol, blockquote, a', () => {
        expect(Object.keys(ELEMENT_DEFAULTS).sort()).toEqual(
            ['a', 'blockquote', 'h1', 'h2', 'h3', 'ol', 'p', 'ul'].sort(),
        );
    });

    it('a default is link blue + underline', () => {
        expect(ELEMENT_DEFAULTS.a).toMatchObject({
            color: '#2563eb',
            textDecoration: 'underline',
        });
    });

    it('paragraph defaults match PRD US-005 table', () => {
        expect(ELEMENT_DEFAULTS.p).toMatchObject({
            fontFamily: 'Arial, Helvetica, sans-serif',
            fontSize: '16px',
            color: '#1f2937',
        });
    });

    it('h1 default uses Georgia serif at 32px', () => {
        expect(ELEMENT_DEFAULTS.h1).toMatchObject({
            fontFamily: 'Georgia, "Times New Roman", serif',
            fontSize: '32px',
            color: '#111827',
        });
    });

    it('blockquote color is muted', () => {
        expect(ELEMENT_DEFAULTS.blockquote.color).toBe('#4b5563');
    });
});

describe('defaultsToCss', () => {
    it('produces a CSS string that targets every default tag', () => {
        const css = defaultsToCss();
        for (const tag of Object.keys(ELEMENT_DEFAULTS)) {
            expect(css).toContain(tag);
        }
        expect(css).toContain('font-family');
        expect(css).toContain('font-size');
        expect(css).toContain('color');
    });

    it('respects an optional selector prefix', () => {
        const css = defaultsToCss('.my-scope');
        expect(css).toContain('.my-scope p');
        expect(css).toContain('.my-scope h1');
    });
});

describe('inlineElementDefaults', () => {
    it('inlines defaults onto block elements that lack inline styles', () => {
        const out = inlineElementDefaults('<p>hello</p>');
        expect(out).toContain('font-family: Arial');
        expect(out).toContain('font-size: 16px');
        expect(out).toContain('color: #1f2937');
    });

    it('preserves an existing inline font-size while filling the missing color/family', () => {
        const out = inlineElementDefaults(
            '<p style="font-size: 24px">x</p>',
        );
        expect(out).toContain('font-size: 24px');
        expect(out).not.toContain('font-size: 16px');
        expect(out).toContain('color: #1f2937');
        expect(out).toContain('font-family: Arial');
    });

    it('does not duplicate styles on a second pass (idempotent)', () => {
        const once = inlineElementDefaults('<p>x</p>');
        const twice = inlineElementDefaults(once);
        expect(twice).toBe(once);
    });

    it('inlines color + text-decoration on bare <a> tags', () => {
        const out = inlineElementDefaults('<p><a href="https://x.com">link</a></p>');
        expect(out).toMatch(/<a[^>]+style="[^"]*color: #2563eb[^"]*"/);
        expect(out).toMatch(/<a[^>]+style="[^"]*text-decoration: underline[^"]*"/);
    });

    it('preserves an existing inline color on <a> while filling decoration', () => {
        const out = inlineElementDefaults('<p><a href="x" style="color: #ff0000">red</a></p>');
        expect(out).toContain('color: #ff0000');
        expect(out).not.toContain('color: #2563eb');
        expect(out).toContain('text-decoration: underline');
    });

    it('inlines for h1, h2, h3, ul, ol, blockquote', () => {
        const out = inlineElementDefaults(
            '<h1>a</h1><h2>b</h2><h3>c</h3><ul><li>x</li></ul><ol><li>y</li></ol><blockquote>z</blockquote>',
        );
        expect(out).toContain('font-size: 32px');
        expect(out).toContain('font-size: 24px');
        expect(out).toContain('font-size: 20px');
        expect(out).toContain('color: #4b5563');
    });

    it('leaves unknown tags untouched', () => {
        const out = inlineElementDefaults('<div>plain</div>');
        expect(out).not.toContain('font-family');
    });

    it('handles empty input', () => {
        expect(inlineElementDefaults('')).toBe('');
    });

    it('respects user-overridden color even if it equals the default', () => {
        const out = inlineElementDefaults(
            '<p style="color: #1f2937">x</p>',
        );
        expect(out).toContain('color: #1f2937');
        // Defaults still fill family + size.
        expect(out).toContain('font-family');
        expect(out).toContain('font-size: 16px');
    });

    it('preserves nested inline marks (span style stays intact)', () => {
        const out = inlineElementDefaults(
            '<p>hi <span style="color: #ff0000">red</span></p>',
        );
        expect(out).toContain('color: #ff0000');
        expect(out).toContain('font-family: Arial');
    });
});
