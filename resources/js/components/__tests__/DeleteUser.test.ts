import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Form: defineComponent({
        template:
            '<form><slot :errors="{}" :processing="false" :reset="() => {}" :clear-errors="() => {}" /></form>',
    }),
}));

import DeleteUser from '../DeleteUser.vue';

const deleteButton = (wrapper: ReturnType<typeof mount>) =>
    wrapper.get('[data-test="delete-user-button"]');

describe('Delete account section', () => {
    it('disables the delete for the instance owner and says why', () => {
        const wrapper = mount(DeleteUser, {
            props: { isInstanceOwner: true },
        });

        expect(deleteButton(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain(
            'The instance owner cannot delete the account.',
        );
    });

    it('lets another user open the delete dialog', () => {
        const wrapper = mount(DeleteUser, {
            props: { isInstanceOwner: false },
        });

        expect(deleteButton(wrapper).attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).not.toContain('instance owner');
    });
});
