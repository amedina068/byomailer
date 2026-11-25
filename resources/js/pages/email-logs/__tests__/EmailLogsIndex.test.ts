import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { get: routerGet },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import EmailLogsIndex from '../Index.vue';

const pagination = (lastPage = 1) => ({
    current_page: 1,
    last_page: lastPage,
    per_page: 25,
    total: 0,
    next_page_url: lastPage > 1 ? '/email-logs?page=2' : null,
    prev_page_url: null,
});

const mountPage = (filters = {}, lastPage = 1) =>
    mount(EmailLogsIndex, {
        props: {
            emailLogs: [],
            pagination: pagination(lastPage),
            filters,
        },
    });

describe('Email logs page', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    it('gives each filter control an accessible name', () => {
        const wrapper = mountPage();

        expect(
            wrapper.find('input[aria-label="Search email logs"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('select[aria-label="Filter by status"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('select[aria-label="Filter by operation"]').exists(),
        ).toBe(true);
    });

    it('says that no log matches when a filter is active', async () => {
        const wrapper = mountPage({ status: 'failed' });

        expect(wrapper.text()).toContain('No email logs match these filters.');
        expect(wrapper.text()).not.toContain('No email logs yet.');

        const clear = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Clear filters');
        expect(clear).toBeDefined();

        await clear!.trigger('click');

        expect(routerGet).toHaveBeenLastCalledWith(
            '/email-logs',
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('keeps the first-run empty state when no filter is active', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('No email logs yet.');
    });

    it('names the page buttons', () => {
        const wrapper = mountPage({}, 3);

        expect(
            wrapper.find('button[aria-label="Previous page"]').exists(),
        ).toBe(true);
        expect(wrapper.find('button[aria-label="Next page"]').exists()).toBe(
            true,
        );
    });

    it('lets the table scroll sideways on a narrow screen', () => {
        const wrapper = mountPage();

        expect(wrapper.get('table').element.parentElement?.className).toContain(
            'overflow-x-auto',
        );
    });
});
