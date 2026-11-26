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
    eventsConfigured: boolean;
    canConfigureEvents: boolean;
};

const newInstance: Props = {
    hasCredentials: false,
    hasVerifiedIdentity: false,
    pendingIdentityId: null,
    sandbox: null,
    eventsConfigured: false,
    canConfigureEvents: true,
};

const queueReady: Partial<Props> = { eventsConfigured: true };

const mountSteps = (overrides: Partial<Props> = {}) =>
    mount(SesSetupSteps, { props: { ...newInstance, ...overrides } });

const step = (wrapper: ReturnType<typeof mountSteps>, id: string) =>
    wrapper.get(`[data-step="${id}"]`);

describe('Dashboard SES setup steps', () => {
    it('shows the SES steps in order on a new instance, the event queue first', () => {
        const wrapper = mountSteps();
        const titles = wrapper
            .findAll('[data-step] h3')
            .map((title) => title.text());

        expect(wrapper.text()).toContain('Connect Amazon SES');
        expect(titles).toEqual([
            'Create the SES event queue',
            'Connect your AWS account',
            'Verify a sending domain',
            'Leave the SES sandbox',
        ]);
    });

    it('makes the event queue the current step for the owner of a new instance', () => {
        const wrapper = mountSteps();

        expect(step(wrapper, 'events').attributes('data-state')).toBe(
            'current',
        );
        expect(step(wrapper, 'events').get('a').attributes('href')).toBe(
            'https://byomailer.com/docs/self-hosting/ses-events',
        );
        expect(step(wrapper, 'aws').attributes('data-state')).toBe('todo');
        expect(step(wrapper, 'aws').find('a').exists()).toBe(false);
    });

    it('tells a user who is not the owner to ask the owner for the queue', () => {
        const wrapper = mountSteps({ canConfigureEvents: false });

        expect(step(wrapper, 'events').text()).toContain(
            'Ask the owner of this instance',
        );
        expect(step(wrapper, 'events').find('a').exists()).toBe(false);
    });

    it('hides the queue step from a user who is not the owner when the queue exists', () => {
        const wrapper = mountSteps({
            ...queueReady,
            canConfigureEvents: false,
        });

        expect(wrapper.find('[data-step="events"]').exists()).toBe(false);
        expect(step(wrapper, 'aws').attributes('data-state')).toBe('current');
    });

    it('makes the AWS connection the current step when the queue exists', () => {
        const wrapper = mountSteps(queueReady);

        expect(step(wrapper, 'events').attributes('data-state')).toBe('done');
        expect(step(wrapper, 'aws').attributes('data-state')).toBe('current');
        expect(step(wrapper, 'aws').get('a').attributes('href')).toBe(
            '/onboarding/aws',
        );
        expect(step(wrapper, 'identity').find('a').exists()).toBe(false);
    });

    it('sends a user with no email identity to the add wizard', () => {
        const wrapper = mountSteps({
            ...queueReady,
            hasCredentials: true,
            sandbox: true,
        });

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
            ...queueReady,
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
            ...queueReady,
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

    it('shows nothing when every step is done', () => {
        const wrapper = mountSteps({
            ...queueReady,
            hasCredentials: true,
            hasVerifiedIdentity: true,
            sandbox: false,
        });

        expect(wrapper.find('[data-step]').exists()).toBe(false);
    });
});
