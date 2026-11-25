import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const pageProps = { hasPostalAddress: true };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

vi.mock('@/routes/profile', () => ({
    edit: () => ({ url: '/settings/profile', method: 'get' }),
}));

import ComplianceWarnings from '../ComplianceWarnings.vue';

describe('ComplianceWarnings', () => {
    beforeEach(() => {
        pageProps.hasPostalAddress = true;
    });

    it('renders nothing when the content and the account are compliant', () => {
        const wrapper = mount(ComplianceWarnings, {
            props: { withoutUnsubscribeLink: [] },
        });

        expect(wrapper.find('[data-test="compliance-warnings"]').exists()).toBe(
            false,
        );
    });

    it('warns about a missing unsubscribe link and names the one-click header', () => {
        const wrapper = mount(ComplianceWarnings, {
            props: { withoutUnsubscribeLink: ['This content'] },
        });

        const warning = wrapper.find(
            '[data-test="compliance-warning-unsubscribe"]',
        );

        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain(
            'This content has no {{ unsubscribe_url }} link.',
        );
        expect(warning.text()).toContain('CAN-SPAM and GDPR');
        expect(warning.text()).toContain('one-click unsubscribe header');
        expect(
            wrapper
                .find('[data-test="compliance-warning-postal-address"]')
                .exists(),
        ).toBe(false);
    });

    it('joins several parts without a link', () => {
        const wrapper = mount(ComplianceWarnings, {
            props: { withoutUnsubscribeLink: ['Step 1', 'Step 2', 'Step 4'] },
        });

        expect(wrapper.text()).toContain(
            'Step 1, Step 2 and Step 4 have no {{ unsubscribe_url }} link.',
        );
    });

    it('links to the content editor when the page gives one', () => {
        const wrapper = mount(ComplianceWarnings, {
            props: {
                withoutUnsubscribeLink: ['This content'],
                contentHref: '/newsletters/7/content',
            },
        });

        expect(
            wrapper
                .find('[data-test="compliance-warning-unsubscribe"] a')
                .attributes('href'),
        ).toBe('/newsletters/7/content');
    });

    it('warns about a missing postal address with a link to the profile', () => {
        pageProps.hasPostalAddress = false;

        const wrapper = mount(ComplianceWarnings, {
            props: { withoutUnsubscribeLink: [] },
        });

        const warning = wrapper.find(
            '[data-test="compliance-warning-postal-address"]',
        );

        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain('Your account has no postal address.');
        expect(warning.find('a').attributes('href')).toBe('/settings/profile');
    });

    it('says that the warnings do not stop the send', () => {
        pageProps.hasPostalAddress = false;

        const wrapper = mount(ComplianceWarnings, {
            props: { withoutUnsubscribeLink: [] },
        });

        expect(wrapper.text()).toContain('BYOMailer does not stop the send.');
    });
});
