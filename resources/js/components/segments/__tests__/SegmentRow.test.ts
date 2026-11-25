import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));

import SegmentRow from '../SegmentRow.vue';

const mountRow = () =>
    mount(SegmentRow, {
        props: {
            segment: {
                id: 7,
                name: 'VIPs',
                description: null,
                filters: [],
            },
            count: 12,
            viewUrl: '/segments/7',
        },
    });

afterEach(() => {
    visit.mockClear();
});

describe('SegmentRow keyboard', () => {
    it('opens the segment when Enter is pressed on the row', async () => {
        const wrapper = mountRow();

        await wrapper.trigger('keydown', { key: 'Enter' });

        expect(visit).toHaveBeenCalledWith('/segments/7');
    });

    it('does not open the segment when Enter or Space is pressed on Archive', async () => {
        const wrapper = mountRow();
        const archive = wrapper.get('button[aria-label="Archive segment"]');

        await archive.trigger('keydown', { key: 'Enter' });
        await archive.trigger('keydown', { key: ' ' });

        expect(visit).not.toHaveBeenCalled();
    });

    it('shows a visible focus ring on the row', () => {
        const classes = mountRow().classes();

        expect(classes).toContain('focus-visible:outline-2');
        expect(classes).not.toContain('focus-visible:outline-none');
    });
});
