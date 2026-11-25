import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const role = { value: 'admin' };
const axiosPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { post: vi.fn(), reload: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: {},
        processing: false,
        post: vi.fn(),
    }),
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

const roleConnection = {
    externalId: 'a'.repeat(64),
    principalArn: 'arn:aws:iam::111122223333:user/byomailer',
    roleName: 'byomailer-ses',
    templateUrl: 'https://byomailer.example/aws/byomailer-ses-role.json',
    quickCreateUrl: null,
};

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

    it('shows the IAM role in place of the access key', () => {
        const wrapper = mount(EmailCredentials, {
            props: {
                credential: {
                    ...credential(),
                    access_key_hint: null,
                    uses_role: true,
                    role_arn: 'arn:aws:iam::123456789012:role/byomailer-ses',
                },
                roleConnection: roleConnection,
            },
        });

        expect(wrapper.text()).toContain(
            'arn:aws:iam::123456789012:role/byomailer-ses',
        );
        expect(wrapper.text()).not.toContain('Access key ending in');
        expect(wrapper.text()).not.toContain('Connect with an IAM role');
    });

    it('offers the IAM role to an account with access keys', () => {
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential(), roleConnection },
        });

        expect(wrapper.text()).toContain('Access key ending in');
        expect(wrapper.text()).toContain('Connect with an IAM role');
    });

    it('does not offer the IAM role when the server has no role principal', () => {
        const wrapper = mount(EmailCredentials, {
            props: { credential: credential(), roleConnection: null },
        });

        expect(wrapper.text()).not.toContain('Connect with an IAM role');
    });
});
