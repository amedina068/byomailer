import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    }),
    Link: defineComponent({
        props: ['href'],
        template:
            '<a :href="typeof href === \'string\' ? href : href.url"><slot /></a>',
    }),
}));
vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import { register } from '@/routes';

import Login from '../Login.vue';

const signUpLink = (canRegister: boolean) =>
    mount(Login, { props: { canResetPassword: true, canRegister } })
        .findAll('a')
        .find((link) => link.text() === 'Sign up');

describe('Login page', () => {
    it('links to the sign-up page when registration is open', () => {
        const link = signUpLink(true);

        expect(link).toBeDefined();
        expect(link?.attributes('href')).toBe(register().url);
    });

    it('hides the sign-up link when registration is closed', () => {
        expect(signUpLink(false)).toBeUndefined();
    });
});
