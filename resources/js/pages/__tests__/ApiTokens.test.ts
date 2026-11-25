import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const role = { value: 'admin' };
const routerDelete = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        template:
            '<form><slot :errors="{}" :processing="false" :recently-successful="false" /></form>',
    }),
    usePage: () => ({ props: { auth: { user: { role: role.value } } } }),
    router: { delete: (...args: unknown[]) => routerDelete(...args) },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));

import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';

import ApiTokens from '../settings/ApiTokens.vue';

const ConfirmDialogStub = defineComponent({
    props: ['isOpen', 'title', 'description', 'confirmLabel'],
    emits: ['confirm', 'update:isOpen'],
    template:
        '<div v-if="isOpen" data-test="confirm-dialog"><p>{{ title }}</p><p>{{ description }}</p><button data-test="confirm" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
});

const mountPage = () =>
    mount(ApiTokens, {
        global: { stubs: { ConfirmDialog: ConfirmDialogStub } },
        props: {
            newToken: null,
            expirationDays: [30, 90, 365],
            tokens: [
                {
                    id: 1,
                    name: 'Legacy',
                    access: 'read_write',
                    expires_at: null,
                    last_used_at: null,
                    created_at: '1 day ago',
                },
                {
                    id: 2,
                    name: 'Reader',
                    access: 'read',
                    expires_at: '2026-12-17',
                    last_used_at: null,
                    created_at: '1 hour ago',
                },
            ],
        },
    });

describe('API tokens page', () => {
    beforeEach(() => {
        role.value = 'admin';
        routerDelete.mockReset();
    });

    it('asks for the access and the expiration of a new token', () => {
        const wrapper = mountPage();

        const access = wrapper.get('select[name="access"]');
        expect(
            access.findAll('option').map((o) => o.attributes('value')),
        ).toEqual(['read', 'read_write']);

        const expiration = wrapper.get('select[name="expires_in_days"]');
        expect(
            expiration.findAll('option').map((o) => o.attributes('value')),
        ).toEqual(['', '30', '90', '365']);
    });

    it('shows the access and the expiration of each token', () => {
        const text = mountPage().text();

        expect(text).toContain('Read and write');
        expect(text).toContain('Does not expire');
        expect(text).toContain('Read only');
        expect(text).toContain('2026-12-17');
    });

    it('does not show the create form to a viewer', () => {
        role.value = 'viewer';
        const wrapper = mountPage();

        expect(wrapper.find('select[name="access"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Viewers have read-only access');
    });

    it('asks for a confirmation before it deletes a token', async () => {
        const wrapper = mountPage();

        const deleteButton = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Delete');
        await deleteButton?.trigger('click');

        expect(routerDelete).not.toHaveBeenCalled();
        const dialog = wrapper.get('[data-test="confirm-dialog"]');
        expect(dialog.text()).toContain('Legacy');
        expect(dialog.text()).toContain('stop working');

        await dialog.get('[data-test="confirm"]').trigger('click');

        expect(routerDelete).toHaveBeenCalledWith(
            ApiTokenController.destroy.url(1),
            expect.anything(),
        );
    });
});
