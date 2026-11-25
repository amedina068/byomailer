import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';

const { post, optimistic } = vi.hoisted(() => {
    const post = vi.fn();

    return { post, optimistic: vi.fn(() => ({ post })) };
});

vi.mock('@inertiajs/vue3', () => ({
    Deferred: defineComponent({ template: '<div><slot /></div>' }),
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { optimistic, post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            processing: false,
            errors: {},
            defaults: vi.fn(),
            clearErrors: vi.fn(),
            reset: vi.fn(),
            patch: vi.fn(),
            post: vi.fn(),
        }),
    usePage: () => ({ props: {} }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({
        props: ['breadcrumbs'],
        template:
            '<main><nav><a v-for="crumb in breadcrumbs" :key="crumb.title" :href="crumb.href">{{ crumb.title }}</a></nav><slot /></main>',
    }),
}));
vi.mock('@/components/ui/confirm-dialog', () => ({
    ConfirmDialog: defineComponent({
        props: ['isOpen', 'title', 'description', 'confirmLabel'],
        emits: ['confirm', 'update:isOpen'],
        template:
            '<div v-if="isOpen" data-test="confirm-dialog"><p>{{ title }}</p><p>{{ description }}</p><button type="button" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
    }),
}));

import { unsubscribe as contactUnsubscribe } from '@/actions/App/Http/Controllers/ContactController';
import { index as contactsIndex } from '@/routes/contacts';

import ContactShow from '../Show.vue';

const baseContact = {
    id: 7,
    name: 'Ada',
    email: 'ada@example.com',
    extra_attributes: {},
    unsubscribed_at: null as string | null,
    unsubscribed_by_contact: false,
    sunset_at: null,
    lists: [{ id: 3, name: 'Weekly' }],
    tags: [{ id: 2, name: 'VIP' }],
    topics: [],
    sequences: [],
};

const mountPage = (contact: Partial<typeof baseContact> = {}) =>
    mount(ContactShow, {
        props: {
            contact: { ...baseContact, ...contact },
            mails: [],
            allTags: [],
            allLists: [],
            allSequences: [],
            contactAttributes: [],
            suppression: null,
        },
        global: {
            stubs: {
                CreateTagModal: true,
                CreateListModal: true,
                AddToSequenceModal: true,
                RemoveSuppressionModal: true,
            },
        },
    });

const buttonWithText = (wrapper: ReturnType<typeof mountPage>, text: string) =>
    wrapper.findAll('button').find((button) => button.text() === text);

beforeEach(() => {
    post.mockClear();
    optimistic.mockClear();
});

describe('Contact page', () => {
    it('links the Contacts breadcrumb to the contacts index', () => {
        const wrapper = mountPage();

        expect(wrapper.find('nav a').attributes('href')).toBe(
            contactsIndex().url,
        );
    });

    it('asks for a confirmation before it unsubscribes the contact', async () => {
        const wrapper = mountPage();

        await buttonWithText(wrapper, 'Unsubscribe')!.trigger('click');

        expect(optimistic).not.toHaveBeenCalled();
        const dialog = wrapper.find('[data-test="confirm-dialog"]');
        expect(dialog.text()).toContain('cancels the pending emails');

        await dialog.find('button').trigger('click');

        expect(post).toHaveBeenCalledWith(
            contactUnsubscribe(7).url,
            {},
            expect.any(Object),
        );
    });

    it('shows the remove buttons of the tag and list chips with the name', () => {
        const wrapper = mountPage();

        const removeTag = wrapper.find('button[aria-label="Remove tag VIP"]');
        const removeList = wrapper.find(
            'button[aria-label="Remove from list Weekly"]',
        );

        expect(removeTag.exists()).toBe(true);
        expect(removeList.exists()).toBe(true);
        expect(removeTag.classes()).not.toContain('hidden');
        expect(removeList.classes()).not.toContain('hidden');
    });

    it('labels the tag and list pickers', async () => {
        const wrapper = mountPage();

        await wrapper.find('button[aria-label="Add tag"]').trigger('click');
        await wrapper.find('button[aria-label="Add list"]').trigger('click');

        expect(wrapper.find('select[aria-label="Tag to add"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('select[aria-label="List to add"]').exists()).toBe(
            true,
        );
    });

    it('labels the inline name and email inputs', async () => {
        const wrapper = mountPage();

        await buttonWithText(wrapper, 'Ada')!.trigger('click');
        await buttonWithText(wrapper, 'ada@example.com')!.trigger('click');

        expect(wrapper.find('input[aria-label="Contact name"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('input[aria-label="Contact email"]').exists()).toBe(
            true,
        );
    });

    it('explains why a contact that unsubscribed itself has no Subscribe button', () => {
        const wrapper = mountPage({
            unsubscribed_at: '2026-11-01T10:00:00Z',
            unsubscribed_by_contact: true,
        });

        expect(buttonWithText(wrapper, 'Subscribe')).toBeUndefined();
        expect(wrapper.text()).toContain(
            'This contact unsubscribed. Only the contact can subscribe again.',
        );
    });
});
