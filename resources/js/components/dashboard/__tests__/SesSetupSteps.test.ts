import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    }),
}));

import SesSetupSteps from '@/components/dashboard/SesSetupSteps.vue';

type Props = {
    hasCredentials: boolean;
    hasVerifiedIdentity: boolean;
    pendingIdentityId: number | null;
    sandbox: boolean | null;
    eventsConfigured: boolean | null;
};

const newInstance: Props = {
    hasCredentials: false,
    hasVerifiedIdentity: false,
    pendingIdentityId: null,
    sandbox: null,
    eventsConfigured: false,
};

const mountSteps = (overrides: Partial<Props> = {}) =>
    mount(SesSetupSteps, { props: { ...newInstance, ...overrides } });

const step = (wrapper: ReturnType<typeof mountSteps>, id: string) =>
    wrapper.get(`[data-step="${id}"]`);

describe('Dashboard SES setup steps', () => {
    it('shows the SES steps in order on a new instance', () => {
        const wrapper = mountSteps();
        const titles = wrapper
            .findAll('[data-step] h3')
            .map((title) => title.text());

        expect(wrapper.text()).toContain('Connect Amazon SES');
        expect(titles).toEqual([
            'Connect your AWS account',
            'Verify a sending domain',
            'Leave the SES sandbox',
            'Receive the SES events',
        ]);
    });

    it('makes the AWS connection the current step', () => {
        const wrapper = mountSteps();

        expect(step(wrapper, 'aws').attributes('data-state')).toBe('current');
        expect(step(wrapper, 'aws').get('a').attributes('href')).toBe(
            '/onboarding/aws',
        );
        expect(step(wrapper, 'identity').attributes('data-state')).toBe('todo');
        expect(step(wrapper, 'identity').find('a').exists()).toBe(false);
    });

    it('sends a user with no email identity to the add wizard', () => {
        const wrapper = mountSteps({ hasCredentials: true, sandbox: true });

        expect(step(wrapper, 'aws').attributes('data-state')).toBe('done');
        expect(step(wrapper, 'identity').attributes('data-state')).toBe(
            'current',
        );
        expect(step(wrapper, 'identity').get('a').attributes('href')).toBe(
            '/settings/identities/create',
        );
    });

    it('sends a user with an email identity that waits for DNS to its page', () => {
        const wrapper = mountSteps({
            hasCredentials: true,
            pendingIdentityId: 42,
        });

        expect(step(wrapper, 'identity').get('a').attributes('href')).toBe(
            '/settings/identities/42',
        );
        expect(step(wrapper, 'identity').get('a').text()).toBe(
            'Finish the DNS setup',
        );
    });

    it('links to the production access guide while the account is in the sandbox', () => {
        const wrapper = mountSteps({
            hasCredentials: true,
            hasVerifiedIdentity: true,
            sandbox: true,
        });

        expect(step(wrapper, 'sandbox').attributes('data-state')).toBe(
            'current',
        );
        expect(step(wrapper, 'sandbox').get('a').attributes('href')).toBe(
            '/documentation/guides/request-production-access',
        );
    });

    it('marks the sandbox step done with production access', () => {
        const wrapper = mountSteps({
            hasCredentials: true,
            hasVerifiedIdentity: true,
            sandbox: false,
        });

        expect(step(wrapper, 'sandbox').attributes('data-state')).toBe('done');
    });

    it('always links the owner to the SES events guide until the queue is set', () => {
        const wrapper = mountSteps();

        expect(step(wrapper, 'events').get('a').attributes('href')).toBe(
            'https://byomailer.com/docs/self-hosting/ses-events',
        );
    });

    it('hides the SES events step from a user who is not the owner', () => {
        const wrapper = mountSteps({ eventsConfigured: null });

        expect(wrapper.find('[data-step="events"]').exists()).toBe(false);
    });

    it('shows nothing when every step is done', () => {
        const wrapper = mountSteps({
            hasCredentials: true,
            hasVerifiedIdentity: true,
            sandbox: false,
            eventsConfigured: true,
        });

        expect(wrapper.find('[data-step]').exists()).toBe(false);
    });
});
