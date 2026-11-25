import { describe, expect, it } from 'vitest';

import { linkKeyLabel, normalizeLinkName } from '../linkClicks';

describe('normalizeLinkName', () => {
    it.each([
        ['Black Friday', 'black-friday'],
        ['  hero_CTA  ', 'hero-cta'],
        ['Café Promo!', 'cafe-promo'],
        ['sale--2026', 'sale-2026'],
        ['news@home', 'news-at-home'],
    ])('normalizes %j to %j', (input, expected) => {
        expect(normalizeLinkName(input)).toBe(expected);
    });

    it('returns null when no letter or digit is left', () => {
        expect(normalizeLinkName('  !!  ')).toBeNull();
        expect(normalizeLinkName('')).toBeNull();
    });

    it('keeps at most 100 characters without a trailing dash', () => {
        expect(normalizeLinkName(`${'a'.repeat(99)} b`)).toBe('a'.repeat(99));
    });
});

describe('linkKeyLabel', () => {
    it.each([
        ['name:black-friday', 'black-friday'],
        ['url:https://example.com/sale', 'https://example.com/sale'],
        ['other', 'other'],
    ])('labels %j as %j', (key, label) => {
        expect(linkKeyLabel(key)).toBe(label);
    });
});
