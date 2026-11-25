import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    router: { visit: vi.fn() },
}));

import Create from '../Create.vue';
import StepSelectIdentity from '../wizard/StepSelectIdentity.vue';
import StepSender from '../wizard/StepSender.vue';

const buttonWithText = (wrapper: ReturnType<typeof mount>, text: string) =>
    wrapper.findAll('button').find((button) => button.text().includes(text))!;

const openSenderStep = async () => {
    const wrapper = mount(Create, {
        props: { existingDomains: [], hasCredential: false },
    });
    const select = wrapper.findComponent(StepSelectIdentity);

    select.vm.$emit('choose-new');
    select.vm.$emit('update:newDomainInput', 'example.com');
    await nextTick();
    await buttonWithText(wrapper, 'Next').trigger('click');
    await buttonWithText(wrapper, 'Next').trigger('click');

    return wrapper;
};

describe('Add email identity wizard, sender step', () => {
    it('says why the local part is not valid while the user types', async () => {
        const wrapper = await openSenderStep();

        wrapper
            .findComponent(StepSender)
            .vm.$emit('update:handle', 'hello world');
        await nextTick();

        expect(wrapper.text()).toContain(
            'Use letters, numbers, dots, dashes, or underscores only.',
        );
        expect(
            buttonWithText(wrapper, 'Save email identity').attributes(
                'disabled',
            ),
        ).toBeDefined();
    });

    it('says why the MAIL FROM domain is not valid while the user types', async () => {
        const wrapper = await openSenderStep();
        const sender = wrapper.findComponent(StepSender);

        sender.vm.$emit('update:handle', 'hello');
        sender.vm.$emit('update:mailFromInput', 'not a domain');
        await nextTick();

        expect(wrapper.text()).toContain(
            'Enter a subdomain of example.com, for example mas.example.com.',
        );
    });

    it('shows no error for a valid local part and MAIL FROM domain', async () => {
        const wrapper = await openSenderStep();

        wrapper.findComponent(StepSender).vm.$emit('update:handle', 'hello');
        await nextTick();

        expect(wrapper.text()).not.toContain('Use letters, numbers');
        expect(wrapper.find('label[for="mail_from_subdomain"]').exists()).toBe(
            true,
        );
        expect(
            buttonWithText(wrapper, 'Save email identity').attributes(
                'disabled',
            ),
        ).toBeUndefined();
    });
});
