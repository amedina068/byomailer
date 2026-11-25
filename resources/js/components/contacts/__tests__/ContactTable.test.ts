import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    Link: defineComponent({
        props: ['href'],
        template:
            '<a :href="typeof href === \'string\' ? href : href.url"><slot /></a>',
    }),
}));

import { show as contactShow } from '@/routes/contacts';

import ContactTable from '../ContactTable.vue';

const contacts = [
    {
        id: 11,
        name: 'Ada',
        email: 'ada@example.com',
        created_at: '2026-11-01T10:00:00Z',
    },
    {
        id: 12,
        name: null,
        email: 'bob@example.com',
        created_at: '2026-11-02T10:00:00Z',
    },
];

describe('ContactTable', () => {
    it('renders each email as a keyboard-reachable link to the contact', () => {
        const wrapper = mount(ContactTable, { props: { contacts } });

        const links = wrapper.findAll('tbody a');

        expect(links.map((link) => link.text())).toEqual([
            'ada@example.com',
            'bob@example.com',
        ]);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            contactShow(11).url,
            contactShow(12).url,
        ]);
    });

    it('marks an unsubscribed contact with a status badge', () => {
        const wrapper = mount(ContactTable, {
            props: {
                contacts: [
                    contacts[0],
                    { ...contacts[1], unsubscribed_at: '2026-11-03T10:00:00Z' },
                ],
            },
        });

        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].text()).not.toContain('Unsubscribed');
        expect(rows[1].text()).toContain('Unsubscribed');
    });

    it('keeps the row click as a mouse shortcut', async () => {
        visit.mockClear();
        const wrapper = mount(ContactTable, { props: { contacts } });

        await wrapper.findAll('tbody tr')[1].trigger('click');

        expect(visit).toHaveBeenCalledWith(contactShow(12).url);
    });
});
