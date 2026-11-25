import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/newsletters' }),
}));

import { useActiveUrl } from '../useActiveUrl';

describe('useActiveUrl', () => {
    const { urlIsActive } = useActiveUrl();

    it('marks the exact page as active', () => {
        expect(urlIsActive('/newsletters', '/newsletters')).toBe(true);
    });

    it('keeps the section active on a detail page', () => {
        expect(urlIsActive('/newsletters', '/newsletters/5')).toBe(true);
        expect(
            urlIsActive('/settings/identities', '/settings/identities/3'),
        ).toBe(true);
    });

    it('does not match a section that only shares a prefix', () => {
        expect(urlIsActive('/automations', '/visual-automations/2')).toBe(
            false,
        );
        expect(urlIsActive('/list', '/lists')).toBe(false);
    });
});
