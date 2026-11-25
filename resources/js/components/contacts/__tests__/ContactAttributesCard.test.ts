import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';

const { remove } = vi.hoisted(() => ({ remove: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: remove, patch: vi.fn() },
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            processing: false,
            errors: {},
            reset: vi.fn(),
            clearErrors: vi.fn(),
            post: vi.fn(),
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

import ContactAttributesCard from '../ContactAttributesCard.vue';

const mountCard = () =>
    mount(ContactAttributesCard, {
        props: {
            contactId: 5,
            attributes: { company: 'Acme' },
            attributeNames: [],
        },
    });

describe('ContactAttributesCard', () => {
    it('asks for a confirmation before it removes an attribute', async () => {
        const wrapper = mountCard();

        await wrapper
            .find('button[aria-label="Remove attribute company"]')
            .trigger('click');

        expect(remove).not.toHaveBeenCalled();
        const dialog = wrapper.find('[data-test="confirm-dialog"]');
        expect(dialog.text()).toContain('This deletes the attribute company');

        await dialog.find('button').trigger('click');

        expect(remove).toHaveBeenCalledWith(
            '/contacts/5/attributes/company',
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="confirm-dialog"]').exists()).toBe(
            false,
        );
    });

    it('labels the input that edits an attribute value', async () => {
        const wrapper = mountCard();

        await wrapper
            .find('button[aria-label="Edit attribute company"]')
            .trigger('click');

        expect(
            wrapper.find('input[aria-label="Value of company"]').exists(),
        ).toBe(true);
    });
});
