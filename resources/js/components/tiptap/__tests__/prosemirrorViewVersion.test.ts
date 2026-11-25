import { describe, expect, it } from 'vitest';

const manifests = import.meta.glob<string>(
    '/node_modules/prosemirror-view/package.json',
    { eager: true, import: 'version' },
);

/**
 * prosemirror-view before 1.42.3 can run script from pasted HTML in the
 * editor. The editor must use a fixed version.
 */
describe('prosemirror-view', () => {
    it('is 1.42.3 or later', () => {
        const version = Object.values(manifests)[0] ?? '0.0.0';
        const [major, minor, patch] = version.split('.').map(Number);

        expect(major).toBe(1);
        expect(minor * 1000 + patch).toBeGreaterThanOrEqual(42 * 1000 + 3);
    });
});
