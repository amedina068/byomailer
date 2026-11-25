import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import AttributeRow from '../AttributeRow.vue';

const baseProps = {
    attribute: { id: 1, name: 'company', default_value: 'Acme' },
    saving: false,
    error: undefined,
};

const mountRow = (props = {}): VueWrapper =>
    mount(AttributeRow, {
        props: { ...baseProps, ...props },
        attachTo: document.body,
    });

const openEditor = async (wrapper: VueWrapper): Promise<void> => {
    await wrapper.find('button').trigger('click');
    await nextTick();
};

describe('AttributeRow save lifecycle', () => {
    it('keeps the form open with the error visible when a save fails', async () => {
        const wrapper = mountRow();
        await openEditor(wrapper);

        await wrapper.setProps({ saving: true });
        await wrapper.setProps({ saving: false, error: 'Too long.' });
        await nextTick();

        expect(wrapper.find('form').exists()).toBe(true);
        expect(wrapper.text()).toContain('Too long.');
        expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('input').attributes('aria-describedby')).toBe(
            'attribute-1-error',
        );

        wrapper.unmount();
    });

    it('closes the form and restores focus to Edit after a successful save', async () => {
        const wrapper = mountRow();
        await openEditor(wrapper);

        await wrapper.setProps({ saving: true });
        await wrapper.setProps({ saving: false });
        await nextTick();
        await nextTick();

        expect(wrapper.find('form').exists()).toBe(false);
        const editButton = wrapper.find('button');
        expect(editButton.text()).toContain('Edit');
        expect(document.activeElement).toBe(editButton.element);

        wrapper.unmount();
    });

    it('emits cancel on reopen so a stale error is cleared', async () => {
        const wrapper = mountRow();
        await openEditor(wrapper);

        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('disables the input and buttons while a save is in flight', async () => {
        const wrapper = mountRow();
        await openEditor(wrapper);

        await wrapper.setProps({ saving: true });

        expect(wrapper.find('input').attributes('disabled')).toBeDefined();
        const buttons = wrapper.findAll('form button');
        expect(buttons).toHaveLength(2);
        for (const button of buttons) {
            expect(button.attributes('disabled')).toBeDefined();
        }

        wrapper.unmount();
    });
});
