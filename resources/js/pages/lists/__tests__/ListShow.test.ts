import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

const poll = vi.hoisted(() => ({
    usePoll: vi.fn(),
    start: vi.fn(),
    stop: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { delete: vi.fn(), reload: vi.fn() },
    usePoll: poll.usePoll,
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import ListShow from '../Show.vue';

type ImportStatus = 'pending' | 'processing' | 'completed' | 'failed';

// The same values and labels that ContactImportStatus sends from the server.
const importStatuses = [
    { value: 'pending', label: 'Pending', in_progress: true },
    { value: 'processing', label: 'Processing', in_progress: true },
    { value: 'completed', label: 'Completed', in_progress: false },
    { value: 'failed', label: 'Failed', in_progress: false },
];

function contactImport(status: ImportStatus) {
    return {
        id: 1,
        status,
        original_filename: 'contacts.csv',
        total_count: 120,
        processed_count: 100,
        skipped_count: 3,
        progress: 86,
        error_message: null,
        created_at: '2026-11-19T10:00:00Z',
    };
}

function mountPage(status: ImportStatus) {
    return mount(ListShow, {
        props: {
            list: {
                id: 7,
                uuid: 'list-uuid',
                name: 'Weekly',
                contacts_count: 100,
                topics_count: 0,
                created_at: '2026-11-01T10:00:00Z',
            },
            imports: [contactImport(status)],
            importStatuses,
            topics: [],
        },
        global: {
            stubs: { TopicModal: true, ConfirmDialog: true },
        },
    });
}

describe('List page imports', () => {
    beforeEach(() => {
        poll.usePoll.mockReset();
        poll.start.mockReset();
        poll.stop.mockReset();
        poll.usePoll.mockReturnValue({ start: poll.start, stop: poll.stop });
    });

    it('reloads the imports while an import is in progress', () => {
        mountPage('processing');

        expect(poll.usePoll).toHaveBeenCalledWith(
            3000,
            { only: ['imports', 'list'] },
            { autoStart: false },
        );
        expect(poll.start).toHaveBeenCalledTimes(1);
    });

    it('stops the reload when the import is complete', async () => {
        const wrapper = mountPage('pending');
        expect(poll.start).toHaveBeenCalledTimes(1);

        await wrapper.setProps({ imports: [contactImport('completed')] });
        await nextTick();

        expect(poll.stop).toHaveBeenCalled();
    });

    it('does not reload when no import is in progress', () => {
        mountPage('completed');

        expect(poll.start).not.toHaveBeenCalled();
    });

    it('shows the status label that the server sends', () => {
        const wrapper = mountPage('failed');

        const cells = wrapper.findAll('tbody tr')[0].findAll('td');
        expect(cells[1].text()).toContain('Failed');
    });

    it('shows the imported and skipped counts, not a second date', () => {
        const wrapper = mountPage('completed');
        const headers = wrapper.findAll('th').map((th) => th.text());

        expect(headers).toContain('Imported');
        expect(headers).toContain('Skipped');

        const cells = wrapper.findAll('tbody tr')[0].findAll('td');
        expect(cells[4].text()).toBe('100');
        expect(cells[5].text()).toBe('3');
    });
});
