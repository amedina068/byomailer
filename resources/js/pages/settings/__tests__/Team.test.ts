import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Form: defineComponent({
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    }),
    router: { post: (...args: unknown[]) => routerPost(...args) },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));

import UserArchiveController from '@/actions/App/Http/Controllers/Settings/UserArchiveController';

import Team from '../Team.vue';

const ConfirmDialogStub = defineComponent({
    props: ['isOpen', 'title', 'description', 'confirmLabel'],
    emits: ['confirm', 'update:isOpen'],
    template:
        '<div v-if="isOpen" data-test="confirm-dialog"><p>{{ title }}</p><p>{{ description }}</p><button data-test="confirm" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
});

const member = (id: number, name: string, isCurrentUser = false) => ({
    id,
    uuid: `uuid-${id}`,
    name,
    email: `${name.toLowerCase()}@example.com`,
    role: 'editor' as const,
    role_label: 'Editor',
    invite_expires_at: null,
    archived_at: null,
    created_at: '2026-11-01T00:00:00Z',
    is_current_user: isCurrentUser,
});

const mountPage = () =>
    mount(Team, {
        props: {
            teamMembers: [member(1, 'Owner', true), member(2, 'Sofia')],
            availableRoles: [
                { value: 'admin', label: 'Admin' },
                { value: 'editor', label: 'Editor' },
            ],
            canManageTeam: true,
            inviteExpiresInHours: 24,
        },
        global: { stubs: { ConfirmDialog: ConfirmDialogStub } },
    });

describe('Team settings page', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('asks for a confirmation before it archives a member', async () => {
        const wrapper = mountPage();

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Archive member')
            ?.trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
        const dialog = wrapper.get('[data-test="confirm-dialog"]');
        expect(dialog.text()).toContain('Sofia');
        expect(dialog.text()).toContain('API tokens');

        await dialog.get('[data-test="confirm"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            UserArchiveController.store.url({ teamMember: 2 }),
            {},
            expect.anything(),
        );
    });

    it('names the member on the role select', () => {
        const labels = mountPage()
            .findAll('select[name="role"]')
            .map((select) => select.attributes('aria-label'));

        expect(labels).toContain('Role of Sofia');
    });

    it('says how long an invitation is valid', () => {
        expect(mountPage().text()).toContain(
            'An invitation expires after 24 hours.',
        );
    });
});
