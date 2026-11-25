import { createHeadManager } from '@inertiajs/core';
import { describe, expect, it } from 'vitest';

/**
 * v3 characterization of the head-manager title mechanism (real @inertiajs/core).
 *
 * v2→v3 change: in @inertiajs/vue3 v3 the `<Head>` component resolves a
 * MODULE-SCOPED head manager that is only initialized by `createInertiaApp`, so
 * `<Head>` can no longer be mounted in isolation (v2 allowed providing a
 * `$headManager` global property — that path is gone). The full
 * `<Head title>` -> `document.title` DOM path is instead covered by the SSR
 * render assertion (task T-A4-SSR / T-C4).
 *
 * These tests lock the underlying v3 head-manager contract that actually drives
 * the title, including that v3 emits a `data-inertia`-tagged `<title>` — which is
 * what pairs with the `app.blade.php` `<title data-inertia>` change (v2 used the
 * bare `inertia` attribute).
 */
describe('v3 head manager title contract (real @inertiajs/core)', () => {
    it('emits a data-inertia <title> built from the title callback', () => {
        const updates: string[][] = [];
        createHeadManager(
            true,
            (t: string) => (t ? `${t} - App` : 'App'),
            (els: string[]) => updates.push(els),
        );

        // Server-mode createHeadManager commits once on creation via onUpdate.
        const last = updates.at(-1) ?? [];
        expect(last.some((el) => el.includes('<title data-inertia'))).toBe(true);
        expect(last.some((el) => el.includes('App'))).toBe(true);
    });

    it('a provider pushes and replaces the managed title (no duplication)', () => {
        const updates: string[][] = [];
        const manager = createHeadManager(
            true,
            (t: string) => t || 'App',
            (els: string[]) => updates.push(els),
        );
        const provider = manager.createProvider();

        provider.update(['<title data-inertia="">Dashboard</title>']);
        const afterFirst = updates.at(-1) ?? [];
        expect(afterFirst.some((el) => el.includes('Dashboard'))).toBe(true);

        provider.update(['<title data-inertia="">Settings</title>']);
        const afterSecond = updates.at(-1) ?? [];
        expect(afterSecond.some((el) => el.includes('Settings'))).toBe(true);
        expect(afterSecond.some((el) => el.includes('Dashboard'))).toBe(false);
        // Exactly one managed <title> element — replaced in place, not duplicated.
        expect(afterSecond.filter((el) => el.includes('<title')).length).toBe(1);
    });
});
