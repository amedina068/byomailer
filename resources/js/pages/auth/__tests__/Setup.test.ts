import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        props: ['action', 'method'],
        template:
            '<form :action="action" :method="method"><slot :errors="{}" :processing="false" /></form>',
    }),
}));
vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: defineComponent({
        props: ['title', 'description'],
        template: '<main><h1>{{ title }}</h1><slot /></main>',
    }),
}));

import Setup from '../Setup.vue';

const setupUrlCommands = [
    { label: 'Docker', command: 'docker logs byomailer' },
    { label: 'Docker Compose', command: 'docker compose logs web' },
    { label: 'Any install', command: 'php artisan byomailer:setup-url' },
];

const mountSetup = (props: {
    token: string;
    tokenIsValid: boolean;
    tokenWasWrong: boolean;
}) => mount(Setup, { props: { ...props, setupUrlCommands } });

describe('Setup page', () => {
    it('tells how to find the setup URL when the token is missing', () => {
        const page = mountSetup({
            token: '',
            tokenIsValid: false,
            tokenWasWrong: false,
        });

        expect(page.text()).toContain('Find the setup URL');
        expect(page.text()).toContain('docker logs byomailer');
        expect(page.text()).toContain('php artisan byomailer:setup-url');
        expect(page.text()).not.toContain('is not correct');
        expect(page.find('input[name="password"]').exists()).toBe(false);
        expect(page.find('form').attributes('method')).toBe('get');
        expect(page.find('input[name="token"]').exists()).toBe(true);
    });

    it('says that a wrong token is not correct', () => {
        const page = mountSetup({
            token: '',
            tokenIsValid: false,
            tokenWasWrong: true,
        });

        expect(page.text()).toContain('The token in this URL is not correct.');
        expect(page.find('input[name="password"]').exists()).toBe(false);
    });

    it('shows the owner form with the token in a hidden field', () => {
        const page = mountSetup({
            token: 'abc123',
            tokenIsValid: true,
            tokenWasWrong: false,
        });

        const token = page.find('input[name="token"]');

        expect(page.text()).toContain('Set up BYOMailer');
        expect(token.attributes('type')).toBe('hidden');
        expect(token.attributes('value')).toBe('abc123');
        expect(page.find('input[name="password"]').exists()).toBe(true);
        expect(page.text()).not.toContain('docker logs byomailer');
    });
});
