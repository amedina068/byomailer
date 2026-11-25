import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { visit: (...args: unknown[]) => visit(...args) },
}));

import LinkClicksPanel from '@/components/links/LinkClicksPanel.vue';

import NewsletterRecipients, {
    type Recipient,
} from '../NewsletterRecipients.vue';

const recipients = (data: Recipient[] = []) => ({
    data,
    links: [],
    current_page: 1,
    last_page: 1,
    from: data.length ? 1 : null,
    to: data.length || null,
    total: data.length,
    prev_page_url: null,
    next_page_url: null,
});

const mountRecipients = (props: Record<string, unknown> = {}) =>
    mount(NewsletterRecipients, {
        props: {
            newsletterId: 5,
            recipients: recipients(),
            statusFilter: 'all',
            statusOptions: ['delivered', 'opened'],
            linkFilter: null,
            importUrl: null,
            ...props,
        },
        global: { stubs: { LinkClicksPanel: true } },
    });

const visitedUrl = (): string => decodeURIComponent(visit.mock.calls[0][0]);

describe('NewsletterRecipients link filter', () => {
    beforeEach(() => visit.mockClear());

    it('filters the recipients by the selected link and keeps the status', async () => {
        const wrapper = mountRecipients({ statusFilter: 'opened' });

        wrapper
            .findComponent(LinkClicksPanel)
            .vm.$emit('select', 'name:black-friday');

        expect(visitedUrl()).toBe(
            '/newsletters/5?status=opened&link=name:black-friday',
        );
        expect(visit.mock.calls[0][1].only).toContain('recipientLinkFilter');
    });

    it('clears the link filter when the active link is selected again', () => {
        const wrapper = mountRecipients({ linkFilter: 'name:black-friday' });

        wrapper
            .findComponent(LinkClicksPanel)
            .vm.$emit('select', 'name:black-friday');

        expect(visitedUrl()).toBe('/newsletters/5');
    });

    it('shows the chip and the clicks column while a link filter is active', async () => {
        const wrapper = mountRecipients({
            linkFilter: 'url:https://example.com/sale',
            recipients: recipients([
                {
                    id: 1,
                    name: 'Ana',
                    email: 'ana@example.com',
                    contact_mail_status: 'opened',
                    link_clicks_count: 3,
                    link_first_clicked_at: '2026-10-27T14:05:12+00:00',
                },
            ]),
        });

        expect(
            wrapper.get('[data-testid="link-filter-chip"]').text(),
        ).toContain('https://example.com/sale');
        expect(wrapper.findAll('th').map((th) => th.text())).toContain(
            'Clicks',
        );
        expect(wrapper.get('tbody tr').findAll('td')[1].text()).toBe('3');

        await wrapper
            .get('button[aria-label="Clear link filter"]')
            .trigger('click');

        expect(visitedUrl()).toBe('/newsletters/5');
    });

    it('says that no recipient clicked the link', () => {
        const wrapper = mountRecipients({ linkFilter: 'name:pricing' });

        expect(wrapper.text()).toContain('No recipients clicked this link.');
        expect(wrapper.findAll('th').map((th) => th.text())).toContain(
            'Clicks',
        );
    });

    it('shows no chip and no clicks column without a link filter', () => {
        const wrapper = mountRecipients();

        expect(wrapper.find('[data-testid="link-filter-chip"]').exists()).toBe(
            false,
        );
        expect(wrapper.findAll('th').map((th) => th.text())).not.toContain(
            'Clicks',
        );
        expect(wrapper.text()).toContain('No recipients yet.');
    });
});
