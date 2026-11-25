import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import ContactsIndex from '../Index.vue';

const props = (filters: Partial<Record<string, unknown>> = {}) => ({
    contacts: {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
        prev_page_url: null,
        next_page_url: null,
    },
    lists: [],
    tags: [],
    sequences: [],
    filters: {
        lists: [],
        tags: [],
        include_unsubscribed: false,
        search: '',
        ...filters,
    },
});

const stubs = {
    CreateContactModal: true,
    AddToSequenceModal: true,
};

describe('Contacts page', () => {
    it('says that only unsubscribed contacts are hidden by default', () => {
        const wrapper = mount(ContactsIndex, {
            props: props(),
            global: { stubs: { ...stubs, ContactTable: true } },
        });

        expect(wrapper.text()).toContain(
            'Unsubscribed contacts are hidden by default.',
        );
        expect(wrapper.text()).not.toContain('Inactive');
    });

    it('labels the search input', () => {
        const wrapper = mount(ContactsIndex, {
            props: props(),
            global: { stubs },
        });

        expect(
            wrapper.find('input[aria-label="Search contacts"]').exists(),
        ).toBe(true);
    });

    it('offers an import when the account has no contacts', () => {
        const wrapper = mount(ContactsIndex, {
            props: props(),
            global: { stubs },
        });

        expect(wrapper.text()).toContain('No contacts yet.');
        expect(wrapper.text()).not.toContain(
            'No contacts match these filters.',
        );
    });

    it('offers to clear the filters when the filters match no contact', async () => {
        const wrapper = mount(ContactsIndex, {
            props: props({ search: 'nobody' }),
            global: { stubs },
        });

        expect(wrapper.text()).toContain('No contacts match these filters.');
        expect(wrapper.text()).not.toContain('No contacts yet.');

        await wrapper
            .find('[data-test="empty-clear-filters"]')
            .trigger('click');

        expect(
            (wrapper.find('input[type="search"]').element as HTMLInputElement)
                .value,
        ).toBe('');
    });
});
