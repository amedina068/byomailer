import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const role = { value: 'admin' };
const axiosPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { role: role.value } } } }),
}));
vi.mock('axios', () => ({
    default: {
        post: (...args: unknown[]) => axiosPost(...args),
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            Boolean(error?.isAxiosError),
    },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: defineComponent({ template: '<section><slot /></section>' }),
}));

import EmailCredentials from '../EmailCredentials.vue';

const credential = (permissionsCheckedAt: string | null = null) => ({
    id: 1,
    type: 'ses',
    region: 'us-east-1',
    access_key_hint: 'WXYZ',
    permissions_checked_at: permissionsCheckedAt,
    production_access_enabled: true,
    sending_enabled: true,
    max_24_hour_send: 50000,
    max_send_rate: 14,
    sent_last_24_hours: 10,
});

const button = (wrapper: ReturnType<typeof mount>, label: string) =>
    wrapper.findAll('button').find((b) => b.text().includes(label));

describe('AWS credentials settings page', () => {
    beforeEach(() => {
        role.value = 'admin';
        axiosPost.mockReset();
    });

    it('disables the AWS credential actions for a viewer', () => {
        role.value = 'viewer';
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential() },
        });

        for (const label of ['Refresh', 'Recheck', 'Edit Credentials']) {
            expect(
                button(wrapper, label)?.attributes('disabled'),
            ).toBeDefined();
        }
        expect(wrapper.text()).toContain('read-only access');
    });

    it('shows the error when the quota refresh fails', async () => {
        axiosPost.mockRejectedValue({
            isAxiosError: true,
            response: { data: { error: 'Failed to fetch quota: timeout' } },
        });
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential() },
        });

        await button(wrapper, 'Refresh')?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Failed to fetch quota: timeout');
    });

    it('does not say that permissions are missing before the first check', () => {
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential(null) },
        });

        expect(wrapper.text()).toContain('have not been checked yet');
        expect(wrapper.text()).not.toContain('Missing Permissions');
    });

    it('shows the permission badge after a check', () => {
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential('2026-11-01T00:00:00Z') },
        });

        expect(wrapper.text()).toContain('Missing Permissions');
    });
});
