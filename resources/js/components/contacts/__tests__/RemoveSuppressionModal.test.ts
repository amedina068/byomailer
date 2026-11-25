import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogClose: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import RemoveSuppressionModal from '../RemoveSuppressionModal.vue';

describe('Remove suppression modal', () => {
    it('names the bounced address and confirms the removal', async () => {
        const wrapper = mount(RemoveSuppressionModal, {
            props: { isOpen: true, email: 'bounced@example.com' },
        });

        expect(wrapper.text()).toContain(
            'bounced@example.com bounced, so BYOMailer stopped mailing it.',
        );

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Remove and subscribe')!
            .trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });
});
