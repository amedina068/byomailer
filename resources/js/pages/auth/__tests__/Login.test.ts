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
import { redirect as googleRedirect } from '@/routes/google';

import Login from '../Login.vue';

const signUpLink = (canRegister: boolean) =>
    mount(Login, { props: { canResetPassword: true, canRegister } })
        .findAll('a')
        .find((link) => link.text() === 'Sign up');

const mountLogin = (props: Record<string, unknown> = {}) =>
    mount(Login, { props: { canResetPassword: true, ...props } });

const googleLink = (canUseGoogle: boolean) =>
    mountLogin({ canUseGoogle }).find('[data-test="google-login-button"]');

describe('Login page', () => {
    it('links to the sign-up page when registration is open', () => {
        const link = signUpLink(true);

        expect(link).toBeDefined();
        expect(link?.attributes('href')).toBe(register().url);
    });

    it('hides the sign-up link when registration is closed', () => {
        expect(signUpLink(false)).toBeUndefined();
    });

    it('links to the Google sign-in when the operator turned it on', () => {
        const link = googleLink(true);

        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe(googleRedirect().url);
        expect(link.text()).toBe('Log in with Google');
    });

    it('hides the Google sign-in when the operator did not turn it on', () => {
        expect(googleLink(false).exists()).toBe(false);
    });

    it('shows why the server refused a Google sign-in', () => {
        const message = 'The Google sign-in did not complete. Try again.';

        expect(
            mountLogin({ canUseGoogle: true, googleError: message }).text(),
        ).toContain(message);
    });
});
