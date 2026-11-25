import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const form = reactive({
    aws_account_id: '',
    region: 'us-east-1',
    errors: {} as Record<string, string>,
    processing: false,
    post,
});

vi.mock('@inertiajs/vue3', () => ({
    useForm: () => form,
}));

import AwsRoleConnect from '../AwsRoleConnect.vue';

const roleConnection = (quickCreateUrl: string | null = null) => ({
    externalId: 'a'.repeat(64),
    principalArn: 'arn:aws:iam::111122223333:user/byomailer',
    roleName: 'byomailer-ses',
    templateUrl: 'https://byomailer.example/aws/byomailer-ses-role.json',
    quickCreateUrl,
});

describe('IAM role connection', () => {
    beforeEach(() => {
        post.mockReset();
        form.errors = {};
        form.processing = false;
    });

    it('shows the values for the stack and a template download', () => {
        const wrapper = mount(AwsRoleConnect, {
            props: { roleConnection: roleConnection() },
        });

        expect(wrapper.text()).toContain(
            'arn:aws:iam::111122223333:user/byomailer',
        );
        expect(wrapper.text()).toContain('a'.repeat(64));
        expect(wrapper.text()).toContain('byomailer-ses');
        expect(wrapper.text()).toContain('Upload a template file');
        expect(wrapper.text()).not.toContain('Open AWS CloudFormation');
        expect(wrapper.find('a[download]').attributes('href')).toBe(
            'https://byomailer.example/aws/byomailer-ses-role.json',
        );
    });

    it('opens the quick-create link in a new tab when the server has one', () => {
        const wrapper = mount(AwsRoleConnect, {
            props: {
                roleConnection: roleConnection(
                    'https://us-east-1.console.aws.amazon.com/cloudformation/home',
                ),
            },
        });

        const link = wrapper.find('a[target="_blank"]');

        expect(link.text()).toContain('Open AWS CloudFormation');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
        expect(wrapper.text()).not.toContain('Upload a template file');
    });

    it('posts the account ID and the region to the role route', async () => {
        const wrapper = mount(AwsRoleConnect, {
            props: { roleConnection: roleConnection() },
        });

        await wrapper.find('form').trigger('submit');

        expect(post).toHaveBeenCalledWith('/onboarding/aws/role', {
            preserveScroll: true,
        });
    });

    it('shows the error of the server under the account ID', () => {
        form.errors = {
            aws_account_id: 'AWS refused to let BYOMailer use the IAM role.',
        };

        const wrapper = mount(AwsRoleConnect, {
            props: { roleConnection: roleConnection() },
        });

        expect(wrapper.text()).toContain(
            'AWS refused to let BYOMailer use the IAM role.',
        );
    });

    it('disables the connect button for a viewer', () => {
        const wrapper = mount(AwsRoleConnect, {
            props: { roleConnection: roleConnection(), readOnly: true },
        });

        expect(
            wrapper.find('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();
    });
});
