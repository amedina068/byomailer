import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { type Component, defineComponent, nextTick } from 'vue';

const axiosPut = vi.fn();

vi.mock('axios', () => ({
    default: {
        put: (...args: unknown[]) => axiosPut(...args),
        post: vi.fn(),
        isAxiosError: () => false,
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn() },
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: defineComponent({
        name: 'Checkbox',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input type="checkbox" />',
    }),
}));

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import ConfirmDialog from '@/components/ui/confirm-dialog/ConfirmDialog.vue';

import IdentityCustomLinkDomainSection from '../IdentityCustomLinkDomainSection.vue';
import IdentityCustomTrackingSection from '../IdentityCustomTrackingSection.vue';

const sections = [
    {
        name: 'tracking domain',
        component: IdentityCustomTrackingSection,
        props: {
            identityId: 1,
            customTrackingDomain: 'track.example.com',
            customTrackingARecordIp: '203.0.113.10',
        },
        inputId: 'custom_tracking_domain',
        removeLabel: 'Remove tracking domain',
    },
    {
        name: 'link domain',
        component: IdentityCustomLinkDomainSection,
        props: {
            identityId: 1,
            customLinkDomain: 'links.example.com',
            customLinkCnameTarget: 'target.example.net',
        },
        inputId: 'custom_link_domain_input',
        removeLabel: 'Remove link domain',
    },
];

describe.each(sections)('Custom $name section', (section) => {
    beforeEach(() => axiosPut.mockReset().mockResolvedValue({ data: {} }));

    const render = () =>
        mount(section.component as Component, {
            props: section.props as Record<string, unknown>,
        });

    it('gives the domain input a label', () => {
        const wrapper = render();

        expect(wrapper.find(`input#${section.inputId}`).exists()).toBe(true);
        expect(wrapper.find(`label[for="${section.inputId}"]`).exists()).toBe(
            true,
        );
    });

    it('asks for a confirmation before it removes the domain', async () => {
        const wrapper = render();

        wrapper
            .findComponent({ name: 'Checkbox' })
            .vm.$emit('update:modelValue', false);
        await nextTick();

        const remove = wrapper
            .findAll('button')
            .find((button) => button.text() === section.removeLabel)!;
        await remove.trigger('click');

        expect(axiosPut).not.toHaveBeenCalled();
        expect(wrapper.findComponent(ConfirmDialog).props('isOpen')).toBe(true);

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await nextTick();

        expect(axiosPut).toHaveBeenCalledOnce();
    });
});
