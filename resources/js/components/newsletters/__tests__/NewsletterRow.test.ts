import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));
vi.mock('@/components/projects/ProjectQuickAssign.vue', () => ({
    default: defineComponent({
        template: '<button type="button" data-test="assign">Assign</button>',
    }),
}));

import NewsletterRow from '../NewsletterRow.vue';

const mountRow = (status = 'draft') =>
    mount(NewsletterRow, {
        attachTo: document.body,
        props: {
            statuses: [
                { value: 'draft', label: 'Draft' },
                { value: 'ab_testing', label: 'A/B testing' },
            ],
            viewUrl: '/newsletters/7',
            projects: [{ id: 1, name: 'Launch' }],
            projectIds: [],
            syncUrl: '/newsletters/7/projects',
            newsletter: {
                id: 7,
                title: 'April update',
                subject: 'April update',
                status,
                metrics: {
                    total: 0,
                    sent: 0,
                    pending: 0,
                    opened: 0,
                    clicked: 0,
                    bounced: 0,
                    unsubscribed: 0,
                    complained: 0,
                    open_rate: null,
                    click_rate: null,
                    unsubscribe_rate: null,
                },
            },
        },
    });

describe('Newsletter row', () => {
    beforeEach(() => {
        visit.mockReset();
    });

    it('opens the newsletter when the user presses Enter on the row', async () => {
        const wrapper = mountRow();

        await wrapper.find('tr').trigger('keydown', { key: 'Enter' });

        expect(visit).toHaveBeenCalledWith('/newsletters/7');
    });

    it('does not open the newsletter when Enter is pressed on a nested control', async () => {
        const wrapper = mountRow();

        await wrapper
            .find('[data-test="assign"]')
            .trigger('keydown', { key: 'Enter' });
        await wrapper
            .find('[data-test="assign"]')
            .trigger('keydown', { key: ' ' });

        expect(visit).not.toHaveBeenCalled();
    });

    it('shows a visible focus style on the row', () => {
        const row = mountRow().find('tr');

        expect(row.classes()).toContain('focus-visible:outline-2');
    });

    it('shows the status label that the server sends', () => {
        expect(mountRow('ab_testing').text()).toContain('A/B testing');
    });
});
