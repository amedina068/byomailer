import { describe, expect, it, beforeEach } from 'vitest';
import { loadRecentColors, pushRecentColor, RECENT_COLORS_KEY, RECENT_COLORS_LIMIT } from '../recentColors';

describe('recentColors', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('returns empty list when storage is empty', () => {
        expect(loadRecentColors()).toEqual([]);
    });

    it('returns empty list when storage contains junk', () => {
        localStorage.setItem(RECENT_COLORS_KEY, 'not-json');
        expect(loadRecentColors()).toEqual([]);

        localStorage.setItem(RECENT_COLORS_KEY, JSON.stringify({ wrong: true }));
        expect(loadRecentColors()).toEqual([]);
    });

    it('filters out invalid hex entries from storage', () => {
        localStorage.setItem(
            RECENT_COLORS_KEY,
            JSON.stringify(['#abcdef', 'red', '#xyzxyz', '#123456']),
        );
        expect(loadRecentColors()).toEqual(['#abcdef', '#123456']);
    });

    it('adds a new color to the top', () => {
        pushRecentColor('#c8ff00');
        expect(loadRecentColors()).toEqual(['#c8ff00']);
    });

    it('moves an existing color to the top (deduped)', () => {
        pushRecentColor('#111111');
        pushRecentColor('#222222');
        pushRecentColor('#111111');

        expect(loadRecentColors()).toEqual(['#111111', '#222222']);
    });

    it('caps the list at RECENT_COLORS_LIMIT', () => {
        for (let i = 0; i < RECENT_COLORS_LIMIT + 3; i++) {
            const hex = `#${i.toString(16).padStart(6, '0')}`;
            pushRecentColor(hex);
        }

        const result = loadRecentColors();
        expect(result).toHaveLength(RECENT_COLORS_LIMIT);
        // Most recent first
        expect(result[0]).toBe(`#${(RECENT_COLORS_LIMIT + 2).toString(16).padStart(6, '0')}`);
    });

    it('normalizes color before storing (lowercase + leading hash)', () => {
        pushRecentColor('AABBCC');
        expect(loadRecentColors()).toEqual(['#aabbcc']);
    });

    it('rejects invalid color silently', () => {
        pushRecentColor('not-a-color');
        expect(loadRecentColors()).toEqual([]);
    });
});
