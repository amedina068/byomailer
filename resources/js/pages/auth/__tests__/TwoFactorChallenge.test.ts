import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        template:
            '<form><slot :errors="{}" :processing="false" :clear-errors="() => {}" /></form>',
    }),
}));
vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import TwoFactorChallenge from '../TwoFactorChallenge.vue';

describe('Two-factor challenge page', () => {
    it('gives the authentication code input an accessible name', () => {
        const wrapper = mount(TwoFactorChallenge);

        expect(wrapper.get('input#otp').attributes('aria-label')).toBe(
            'Authentication code',
        );
    });

    it('gives the recovery code input an accessible name', async () => {
        const wrapper = mount(TwoFactorChallenge);

        await wrapper.get('button[type="button"]').trigger('click');
        await nextTick();

        expect(
            wrapper.get('input[name="recovery_code"]').attributes('aria-label'),
        ).toBe('Recovery code');
    });
});
