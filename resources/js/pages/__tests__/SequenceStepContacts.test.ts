import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
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
