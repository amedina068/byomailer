import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const role = vi.hoisted(() => ({ value: 'admin' }));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ template: '<a><slot /></a>' }),
    usePage: () => ({
        url: '/settings/profile',
        props: { auth: { user: { role: role.value } } },
    }),
}));

import Layout from '../Layout.vue';

const navLabels = (userRole: string) => {
    role.value = userRole;

    return mount(Layout)
        .findAll('nav a')
        .map((link) => link.text());
};

describe('Settings layout', () => {
    it('shows Webhooks to an admin', () => {
        expect(navLabels('admin')).toContain('Webhooks');
    });

    it.each(['editor', 'viewer'])('hides Webhooks from a %s', (userRole) => {
        expect(navLabels(userRole)).not.toContain('Webhooks');
    });

    it('uses the product terms in the navigation', () => {
        const labels = navLabels('admin');

        expect(labels).toContain('AWS credentials');
        expect(labels).toContain('Email identities');
        expect(labels).not.toContain('Email Credentials');
    });
});
