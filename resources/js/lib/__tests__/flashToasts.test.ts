import { describe, expect, it } from 'vitest';

import {
    type FinishedVisit,
    flashToasts,
    visitRefreshedFlash,
} from '../flashToasts';

const visit = (overrides: Partial<FinishedVisit> = {}): FinishedVisit => ({
    cancelled: false,
    interrupted: false,
    only: [],
    except: [],
    ...overrides,
});

describe('visitRefreshedFlash', () => {
    it('is true for a full visit', () => {
        expect(visitRefreshedFlash(visit())).toBe(true);
    });

    it('is false for a cancelled or an interrupted visit', () => {
        expect(visitRefreshedFlash(visit({ cancelled: true }))).toBe(false);
        expect(visitRefreshedFlash(visit({ interrupted: true }))).toBe(false);
    });

    it('is false for a partial reload that does not ask for flash', () => {
        expect(visitRefreshedFlash(visit({ only: ['contacts'] }))).toBe(false);
    });

    it('is true for a partial reload that asks for flash', () => {
        expect(visitRefreshedFlash(visit({ only: ['flash'] }))).toBe(true);
        expect(visitRefreshedFlash(visit({ only: ['step', 'flash'] }))).toBe(
            true,
        );
    });

    it('is false when the visit excludes flash', () => {
        expect(visitRefreshedFlash(visit({ except: ['flash'] }))).toBe(false);
    });
});

describe('flashToasts', () => {
    it('returns a success toast and an error toast', () => {
        expect(
            flashToasts(visit(), {
                success: 'Saved.',
                error: 'SES rejected the message.',
            }),
        ).toEqual([
            { variant: 'success', message: 'Saved.' },
            { variant: 'error', message: 'SES rejected the message.' },
        ]);
    });

    it('returns only the error toast when the flash has no success message', () => {
        expect(
            flashToasts(visit({ only: ['flash'] }), {
                success: null,
                error: 'Please select a sender before sending a test email.',
            }),
        ).toEqual([
            {
                variant: 'error',
                message: 'Please select a sender before sending a test email.',
            },
        ]);
    });

    it('returns nothing when the flash is empty or missing', () => {
        expect(flashToasts(visit(), { success: null, error: null })).toEqual(
            [],
        );
        expect(flashToasts(visit(), undefined)).toEqual([]);
    });

    it('does not repeat an old flash after a partial reload without flash', () => {
        expect(
            flashToasts(visit({ only: ['contacts'] }), {
                success: 'Saved.',
                error: 'Failed.',
            }),
        ).toEqual([]);
    });

    it('does not repeat an old flash after a cancelled visit', () => {
        expect(
            flashToasts(visit({ cancelled: true }), { error: 'Failed.' }),
        ).toEqual([]);
    });
});
