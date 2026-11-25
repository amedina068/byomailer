import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const role = { value: 'admin' };

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    router: { post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: { role: role.value } } } }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));

import Storage from '../Storage.vue';

const setting = {
    id: 1,
    provider: 's3',
    bucket: 'media',
    region: 'us-east-1',
    endpoint: null,
    public_base_url: 'https://media.example.com',
    path_prefix: 'client-1',
    is_verified: true,
    updated_at: null,
};

const button = (wrapper: ReturnType<typeof mount>, label: string) =>
    wrapper.findAll('button').find((b) => b.text().includes(label));

describe('Storage settings page', () => {
    beforeEach(() => {
        role.value = 'admin';
    });

    it('lets an admin change the storage', () => {
        const wrapper = mount(Storage, { props: { setting } });

        expect(
            button(wrapper, 'Edit Settings')?.attributes('disabled'),
        ).toBeUndefined();
        expect(wrapper.text()).not.toContain('read-only access');
    });

    it('disables the storage actions for a viewer', () => {
        role.value = 'viewer';
        const wrapper = mount(Storage, { props: { setting } });

        for (const label of ['Edit Settings', 'Test Connection', 'Remove']) {
            expect(
                button(wrapper, label)?.attributes('disabled'),
            ).toBeDefined();
        }
        expect(wrapper.text()).toContain('read-only access');
    });

    it('does not open the storage form for a viewer', () => {
        role.value = 'viewer';
        const wrapper = mount(Storage, { props: { setting: null } });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(
            button(wrapper, 'Configure Storage')?.attributes('disabled'),
        ).toBeDefined();
    });
});
