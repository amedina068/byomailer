import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ template: '<a><slot /></a>' }),
    usePage: () => ({
        props: {
            onboardingChecklist: {
                completed: 1,
                total: 3,
                steps: [
                    {
                        key: 'aws',
                        label: 'Connect AWS',
                        completed: true,
                        href: '/a',
                    },
                    {
                        key: 'identity',
                        label: 'Add an email identity',
                        completed: false,
                        href: '/b',
                    },
                    {
                        key: 'list',
                        label: 'Create a list',
                        completed: false,
                        href: '/c',
                    },
                ],
            },
        },
    }),
}));

import OnboardingChecklist from '../OnboardingChecklist.vue';

describe('OnboardingChecklist', () => {
    it('tells assistive technology whether the guide is open', async () => {
        const wrapper = mount(OnboardingChecklist, {
            global: { stubs: { Transition: false } },
        });
        const toggle = wrapper.get(
            'button[aria-controls="onboarding-checklist-panel"]',
        );

        expect(toggle.attributes('aria-expanded')).toBe('false');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(
            wrapper.find('button[aria-label="Close setup guide"]').exists(),
        ).toBe(true);
    });
});
