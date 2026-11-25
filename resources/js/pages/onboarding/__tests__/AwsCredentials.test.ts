import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    router: { post: vi.fn() },
}));
vi.mock('@/layouts/onboarding/OnboardingLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import AwsCredentials from '../AwsCredentials.vue';

describe('AWS onboarding page', () => {
    it('does not ask for a webhook URL in the credentials step', async () => {
        const wrapper = mount(AwsCredentials);

        (wrapper.vm as unknown as { currentStep: number }).currentStep = 3;
        await nextTick();

        expect(wrapper.text()).toContain('Enter Your AWS Credentials');
        expect(wrapper.text()).not.toContain('SNS_WEBHOOK_ENDPOINT');
    });
});

const jsonResponse = (status: number, body: unknown) =>
    ({ ok: status < 400, status, json: async () => body }) as Response;

const submitCredentials = async (response: Response) => {
    const fetchMock = vi.fn().mockResolvedValue(response);
    vi.stubGlobal('fetch', fetchMock);

    const wrapper = mount(AwsCredentials);
    const vm = wrapper.vm as unknown as {
        currentStep: number;
        accessKey: string;
        secretKey: string;
        region: string;
        verifyAndSaveCredentials: () => Promise<void>;
    };
    vm.currentStep = 3;
    vm.accessKey = 'bad';
    vm.secretKey = 'short';
    vm.region = 'nowhere';
    await vm.verifyAndSaveCredentials();
    await nextTick();

    return { wrapper, fetchMock };
};

describe('AWS credentials step', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('asks the server for a JSON response', async () => {
        const { fetchMock } = await submitCredentials(
            jsonResponse(422, { error: 'Invalid' }),
        );

        expect(fetchMock.mock.calls[0][1].headers.Accept).toBe(
            'application/json',
        );
    });

    it('shows each validation error under its field', async () => {
        const { wrapper } = await submitCredentials(
            jsonResponse(422, {
                message: 'The given data was invalid.',
                errors: {
                    access_key: ['The access key must be valid.'],
                    region: ['The region must be valid.'],
                },
            }),
        );

        expect(wrapper.text()).toContain('The access key must be valid.');
        expect(wrapper.text()).toContain('The region must be valid.');
        expect(wrapper.text()).not.toContain(
            'An error occurred while verifying credentials',
        );
    });

    it('tells the user to reload the page when the session expired', async () => {
        const { wrapper } = await submitCredentials(
            jsonResponse(419, { message: 'CSRF token mismatch.' }),
        );

        expect(wrapper.text()).toContain('Your session expired.');
        expect(wrapper.text()).toContain('Reload the page');
    });

    it('warns that saving replaces the current AWS credentials', async () => {
        const wrapper = mount(AwsCredentials, {
            props: { hasCredentials: true },
        });
        (wrapper.vm as unknown as { currentStep: number }).currentStep = 3;
        await nextTick();

        expect(wrapper.text()).toContain(
            'replace your current AWS credentials',
        );
    });

    it('does not warn when the account has no AWS credentials', async () => {
        const wrapper = mount(AwsCredentials);
        (wrapper.vm as unknown as { currentStep: number }).currentStep = 3;
        await nextTick();

        expect(wrapper.text()).not.toContain(
            'replace your current AWS credentials',
        );
    });
});

describe('IAM policy copy button', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('says "Copied" after a copy', async () => {
        vi.stubGlobal('navigator', {
            clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
        });
        const wrapper = mount(AwsCredentials);
        (wrapper.vm as unknown as { currentStep: number }).currentStep = 2;
        await nextTick();

        const button = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Copy');
        await button?.trigger('click');
        await flushPromises();

        expect(
            wrapper.findAll('button').some((b) => b.text() === 'Copied'),
        ).toBe(true);
    });
});
