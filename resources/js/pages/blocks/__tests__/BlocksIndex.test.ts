import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { destroy } = vi.hoisted(() => ({ destroy: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    router: { delete: destroy },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/components/ui/confirm-dialog', () => ({
    ConfirmDialog: defineComponent({
        name: 'ConfirmDialog',
        props: ['isOpen', 'title', 'description', 'confirmLabel'],
        emits: ['confirm', 'update:isOpen'],
        template: `<div v-if="isOpen" data-test="confirm-dialog">
            <p>{{ title }}</p>
            <button data-test="confirm" @click="$emit('confirm')">{{ confirmLabel }}</button>
        </div>`,
    }),
}));

import BlocksIndex from '../Index.vue';

const block = {
    id: 7,
    uuid: 'block-uuid',
    name: 'footer',
    html_content: '<p>Footer</p>',
    created_at: '2026-11-01T00:00:00Z',
    updated_at: '2026-11-01T00:00:00Z',
};

const deleteButton = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('button').find((button) => button.text() === 'Delete')!;

describe('Blocks index page', () => {
    afterEach(() => {
        destroy.mockReset();
        vi.unstubAllGlobals();
    });

    it('asks for confirmation in a dialog, not in a browser prompt', async () => {
        const nativeConfirm = vi.fn(() => true);
        vi.stubGlobal('confirm', nativeConfirm);
        const wrapper = mount(BlocksIndex, { props: { blocks: [block] } });

        await deleteButton(wrapper).trigger('click');

        expect(nativeConfirm).not.toHaveBeenCalled();
        expect(destroy).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="confirm-dialog"]').text()).toContain(
            'Delete the block "footer"?',
        );

        await wrapper.find('[data-test="confirm"]').trigger('click');

        expect(destroy).toHaveBeenCalledWith('/blocks/7', expect.anything());
    });

    it('lets the table scroll sideways on a narrow screen', () => {
        const wrapper = mount(BlocksIndex, { props: { blocks: [block] } });

        expect(
            wrapper.find('table').element.parentElement?.className,
        ).toContain('overflow-x-auto');
    });
});
