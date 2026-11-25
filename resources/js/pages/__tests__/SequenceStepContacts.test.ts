import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Deferred: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                slots.fallback?.(),
    }),
    Head: defineComponent({ template: '<span />' }),
    router: { visit: (...args: unknown[]) => visit(...args) },
    usePage: () => ({ props: { auth: { timezone: 'UTC' } } }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/components/contacts/PaginatedContactsTable.vue', () => ({
    default: defineComponent({
        props: { contacts: { type: Object, required: true } },
        template: `<div>
            <slot name="header" />
            <table><tbody>
                <tr v-for="contact in contacts.data" :key="contact.id">
                    <slot name="extra-columns" :contact="contact" />
                </tr>
            </tbody></table>
        </div>`,
    }),
}));

import LinkClicksPanel from '@/components/links/LinkClicksPanel.vue';

import StepContacts from '../sequences/StepContacts.vue';

describe('Sequence step contacts export', () => {
    it.each(['all', 'clicked', 'unsubscribed'] as const)(
        'downloads the full history CSV for the active %s filter',
        (filter) => {
            const wrapper = mount(StepContacts, {
                props: {
                    sequence: { id: 12, name: 'Onboarding' },
                    step: { id: 34, message: { subject: 'Welcome' } },
                    filter,
                    stats: { contacts: 0, clicked: 0, unsubscribed: 0 },
                    contacts: {
                        data: [],
                        links: [],
                        total: 0,
                        last_page: 1,
                        from: null,
                        to: null,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                },
            });
            const download = wrapper.get('a[download]');
            expect(download.text()).toBe('Export history CSV');
            expect(download.attributes('href')).toBe(
                '/sequences/12/steps/34/contact-mails/export?filter=' + filter,
            );
        },
    );
});

describe('Sequence step contacts status', () => {
    type StatusFields = {
        send_status: 'failed' | 'pending';
        step_failure_reason: string | null;
    };

    const contactsWith = (contact: StatusFields) => ({
        data: [
            {
                id: 1,
                name: 'Ada',
                email: 'ada@example.com',
                created_at: '2026-11-01T00:00:00Z',
                send_date: '2026-11-20T10:00:00Z',
                ...contact,
            },
        ],
        links: [],
        total: 1,
        last_page: 1,
        from: 1,
        to: 1,
        prev_page_url: null,
        next_page_url: null,
    });

    const mountWith = (contact: StatusFields) =>
        mount(StepContacts, {
            props: {
                sequence: { id: 12, name: 'Onboarding' },
                step: { id: 34, message: { subject: 'Welcome' } },
                filter: 'all',
                stats: { contacts: 1, clicked: 0, unsubscribed: 0 },
                contacts: contactsWith(contact),
            },
        });

    it('shows a failed mail as failed with its reason', () => {
        const wrapper = mountWith({
            send_status: 'failed',
            step_failure_reason: 'blacklisted',
        });

        expect(wrapper.text()).toContain('Failed');
        expect(wrapper.text()).not.toContain('Pending');
        expect(wrapper.get('p[title="blacklisted"]').text()).toBe(
            'blacklisted',
        );
    });

    it('shows no failure reason for a pending mail', () => {
        const wrapper = mountWith({
            send_status: 'pending',
            step_failure_reason: null,
        });

        expect(wrapper.text()).toContain('Pending');
        expect(wrapper.find('p[title]').exists()).toBe(false);
    });
});

describe('Sequence step contacts link filter', () => {
    const mountWithLink = (
        filter: 'all' | 'clicked' | 'unsubscribed',
        linkFilter: string | null,
    ) =>
        mount(StepContacts, {
            props: {
                sequence: { id: 12, name: 'Onboarding' },
                step: { id: 34, message: { subject: 'Welcome' } },
                filter,
                linkFilter,
                stats: { contacts: 0, clicked: 0, unsubscribed: 0 },
                contacts: {
                    data: [],
                    links: [],
                    total: 0,
                    last_page: 1,
                    from: null,
                    to: null,
                    prev_page_url: null,
                    next_page_url: null,
                },
            },
        });

    const visitedUrl = (): string => decodeURIComponent(visit.mock.calls[0][0]);

    beforeEach(() => visit.mockClear());

    it('shows no chip without a link filter', () => {
        const wrapper = mountWithLink('all', null);

        expect(wrapper.find('[data-testid="link-filter-chip"]').exists()).toBe(
            false,
        );
    });

    it.each([
        ['name:black-friday', 'black-friday'],
        ['url:https://example.com/sale', 'https://example.com/sale'],
    ])('labels the chip for %s without the key prefix', (key, label) => {
        const chip = mountWithLink('all', key).get(
            '[data-testid="link-filter-chip"]',
        );

        expect(chip.text()).toContain(label);
        expect(chip.text()).not.toContain(key.split(':')[0] + ':');
    });

    it('clears only the link and keeps the active filter', async () => {
        const wrapper = mountWithLink('clicked', 'name:black-friday');

        await wrapper
            .get('button[aria-label="Clear link filter"]')
            .trigger('click');

        expect(visitedUrl()).toBe(
            '/sequences/12/steps/34/contacts?filter=clicked',
        );
    });

    it('filters the contacts by the link that the panel selects', () => {
        const wrapper = mountWithLink('clicked', null);

        wrapper
            .findComponent(LinkClicksPanel)
            .vm.$emit('select', 'name:pricing');

        expect(visitedUrl()).toBe(
            '/sequences/12/steps/34/contacts?filter=clicked&link=name:pricing',
        );
    });
});
