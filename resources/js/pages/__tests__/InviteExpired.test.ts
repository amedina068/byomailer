import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
}));
vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import { home } from '@/routes';

import Expired from '../invites/Expired.vue';

describe('Expired invite page', () => {
    it('links "Return home" to the home url', () => {
        const wrapper = mount(Expired, {
            props: { message: 'This invitation has expired.' },
        });

        const link = wrapper.get('a');

        expect(link.text()).toBe('Return home');
        expect(link.attributes('href')).toBe(home().url);
        expect(link.attributes('href')).not.toContain('[object Object]');
    });
});
