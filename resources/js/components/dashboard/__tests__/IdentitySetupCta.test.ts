import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    }),
}));

import IdentitySetupCta from '@/components/dashboard/IdentitySetupCta.vue';

describe('Dashboard email identity call to action', () => {
    it('sends a user with no email identity to the add wizard', () => {
        const wrapper = mount(IdentitySetupCta, {
            props: { pendingIdentityId: null },
        });

        expect(wrapper.text()).toContain('Add a verified email identity');
        expect(wrapper.get('a').attributes('href')).toBe(
            '/settings/identities/create',
        );
        expect(wrapper.get('a').text()).toBe('Add email identity');
    });

    it('sends a user with an email identity that waits for DNS to its page', () => {
        const wrapper = mount(IdentitySetupCta, {
            props: { pendingIdentityId: 42 },
        });

        expect(wrapper.text()).toContain('Finish the DNS setup');
        expect(wrapper.get('a').attributes('href')).toBe(
            '/settings/identities/42',
        );
        expect(wrapper.get('a').text()).toBe('Finish DNS setup');
    });
});
