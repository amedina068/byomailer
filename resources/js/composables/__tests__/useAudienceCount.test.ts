import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({
    post: vi.fn(() => Promise.resolve({ data: { count: 42 } })),
}));

vi.mock('axios', () => ({ default: { post } }));

vi.mock('@/routes/newsletters/contacts', () => ({
    preview: () => ({ url: '/newsletters/contacts/preview' }),
}));

import { useAudienceCount } from '../useAudienceCount';

describe('useAudienceCount', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        post.mockClear();
        post.mockResolvedValue({ data: { count: 42 } });
    });

    it('fetch() posts the filters and stores the returned count', async () => {
        const { count, loading, fetch } = useAudienceCount();

        const pending = fetch([{ category: 'tags' }] as never);
        expect(loading.value).toBe(true);

        await pending;

        expect(post).toHaveBeenCalledWith('/newsletters/contacts/preview', {
            filters: [{ category: 'tags' }],
        });
        expect(count.value).toBe(42);
        expect(loading.value).toBe(false);
    });

    it('schedule() debounces and clears the count until the timer fires', async () => {
        const { count, schedule } = useAudienceCount(600);
        count.value = 10;

        schedule([] as never);
        expect(count.value).toBeNull();
        expect(post).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(600);

        expect(post).toHaveBeenCalledTimes(1);
        expect(count.value).toBe(42);
    });

    it('schedule() collapses rapid calls into a single request', async () => {
        const { schedule } = useAudienceCount(600);

        schedule([] as never);
        schedule([] as never);
        schedule([] as never);

        await vi.advanceTimersByTimeAsync(600);

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('keeps the count null when the request fails', async () => {
        post.mockRejectedValueOnce(new Error('network'));
        const { count, loading, fetch } = useAudienceCount();

        await fetch([] as never);

        expect(count.value).toBeNull();
        expect(loading.value).toBe(false);
    });
});
