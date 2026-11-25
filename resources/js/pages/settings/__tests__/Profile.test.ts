import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    Deferred: defineComponent({ template: '<div><slot /></div>' }),
    Form: defineComponent({
        template:
            '<form><slot :errors="{}" :processing="false" :recently-successful="false" /></form>',
    }),
    usePage: () => ({
        props: {
            auth: {
                user: {
                    name: 'Ana',
                    email: 'ana@example.com',
                    email_verified_at: '2026-11-01',
                },
                timezone: 'UTC',
            },
        },
    }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));
vi.mock('@/components/DeleteUser.vue', () => ({
    default: defineComponent({ template: '<div />' }),
}));

import Profile from '../Profile.vue';

describe('Profile settings page', () => {
    it('puts the team-wide fields under a Team settings heading', () => {
        const text = mount(Profile, {
            props: {
                mustVerifyEmail: true,
                timezones: ['UTC'],
                sunsetInactiveDays: null,
                postalAddress: null,
                isInstanceOwner: false,
            },
        }).text();

        expect(text).toContain('Team settings');
        expect(text).toContain('apply to the whole team');
        expect(text.indexOf('Team settings')).toBeLessThan(
            text.indexOf('Timezone'),
        );
        expect(text.indexOf('Email address')).toBeLessThan(
            text.indexOf('Team settings'),
        );
    });
});
