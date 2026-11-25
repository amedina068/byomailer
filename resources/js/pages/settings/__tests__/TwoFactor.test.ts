import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const routerDelete = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        template: '<form><slot :processing="false" /></form>',
    }),
    router: { delete: (...args: unknown[]) => routerDelete(...args) },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));
vi.mock('@/components/TwoFactorRecoveryCodes.vue', () => ({
    default: defineComponent({ template: '<div />' }),
}));
vi.mock('@/components/TwoFactorSetupModal.vue', () => ({
    default: defineComponent({ template: '<div />' }),
}));

import { disable } from '@/routes/two-factor';

import TwoFactor from '../TwoFactor.vue';

const ConfirmDialogStub = defineComponent({
    props: ['isOpen', 'title', 'description', 'confirmLabel'],
    emits: ['confirm', 'update:isOpen'],
    template:
        '<div v-if="isOpen" data-test="confirm-dialog"><p>{{ title }}</p><p>{{ description }}</p><button data-test="confirm" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
});

describe('Two-factor settings page', () => {
    it('asks for a confirmation before it disables two-factor authentication', async () => {
        const wrapper = mount(TwoFactor, {
            props: { twoFactorEnabled: true },
            global: { stubs: { ConfirmDialog: ConfirmDialogStub } },
        });

        const button = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Disable 2FA'));
        await button?.trigger('click');

        expect(routerDelete).not.toHaveBeenCalled();

        await wrapper
            .get('[data-test="confirm-dialog"] [data-test="confirm"]')
            .trigger('click');

        expect(routerDelete).toHaveBeenCalledWith(
            disable.url(),
            expect.anything(),
        );
    });
});
