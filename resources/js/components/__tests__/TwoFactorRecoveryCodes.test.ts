import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Form: defineComponent({
        template: '<form><slot :processing="false" /></form>',
    }),
    router: { post: (...args: unknown[]) => routerPost(...args) },
}));
vi.mock('@/composables/useTwoFactorAuth', () => ({
    useTwoFactorAuth: () => ({
        recoveryCodesList: ref(['code-one', 'code-two']),
        fetchRecoveryCodes: vi.fn(),
        errors: ref([]),
    }),
}));

import { regenerateRecoveryCodes } from '@/routes/two-factor';

import TwoFactorRecoveryCodes from '../TwoFactorRecoveryCodes.vue';

const ConfirmDialogStub = defineComponent({
    props: ['isOpen', 'title', 'description', 'confirmLabel'],
    emits: ['confirm', 'update:isOpen'],
    template:
        '<div v-if="isOpen" data-test="confirm-dialog"><p>{{ title }}</p><p>{{ description }}</p><button data-test="confirm" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
});

describe('Two-factor recovery codes', () => {
    it('asks for a confirmation before it makes new codes', async () => {
        Element.prototype.scrollIntoView = vi.fn();
        const wrapper = mount(TwoFactorRecoveryCodes, {
            global: { stubs: { ConfirmDialog: ConfirmDialogStub } },
        });

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('View'))
            ?.trigger('click');
        await flushPromises();

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Regenerate Codes'))
            ?.trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
        const dialog = wrapper.get('[data-test="confirm-dialog"]');
        expect(dialog.text()).toContain('stop working');

        await dialog.get('[data-test="confirm"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            regenerateRecoveryCodes.url(),
            {},
            expect.anything(),
        );
    });
});
