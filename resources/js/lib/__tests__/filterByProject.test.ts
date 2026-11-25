import { describe, expect, it } from 'vitest';

import { matchesProjectFilter, normalizeProjectFilter } from '../filterByProject';

const validIds = new Set([1, 2, 3]);

describe('normalizeProjectFilter', () => {
    it('returns "all" for the all filter', () => {
        expect(normalizeProjectFilter('all', validIds)).toBe('all');
    });

    it('returns the numeric id for a valid project id', () => {
        expect(normalizeProjectFilter('2', validIds)).toBe(2);
    });

    it('falls back to "all" for a stale/deleted project id', () => {
        expect(normalizeProjectFilter('99', validIds)).toBe('all');
    });

    it('falls back to "all" for a garbage string', () => {
        expect(normalizeProjectFilter('banana', validIds)).toBe('all');
    });

    it('falls back to "all" for a non-integer numeric string', () => {
        expect(normalizeProjectFilter('1.5', validIds)).toBe('all');
    });
});

describe('matchesProjectFilter', () => {
    it('matches everything when filter is "all"', () => {
        expect(matchesProjectFilter([1], 'all', validIds)).toBe(true);
        expect(matchesProjectFilter([], 'all', validIds)).toBe(true);
        expect(matchesProjectFilter(undefined, 'all', validIds)).toBe(true);
    });

    it('matches when the newsletter belongs to the filtered project', () => {
        expect(matchesProjectFilter([1, 2], '2', validIds)).toBe(true);
    });

    it('does not match when the newsletter is not in the filtered project', () => {
        expect(matchesProjectFilter([1], '2', validIds)).toBe(false);
        expect(matchesProjectFilter([], '2', validIds)).toBe(false);
    });

    it('treats a stale project id as "all" and matches everything', () => {
        expect(matchesProjectFilter([1], '99', validIds)).toBe(true);
        expect(matchesProjectFilter(undefined, '99', validIds)).toBe(true);
    });

    it('treats a garbage string as "all" and matches everything', () => {
        expect(matchesProjectFilter([], 'banana', validIds)).toBe(true);
    });

    it('does not match undefined projectIds against a valid project filter', () => {
        expect(matchesProjectFilter(undefined, '1', validIds)).toBe(false);
    });
});
