import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogClose: passThrough,
        DialogContent: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import { TrackedLink } from '@/components/tiptap/TrackedLink';

import InsertLinkModal from '../InsertLinkModal.vue';

let editor: Editor;

const openModal = async (content: string) => {
    editor = new Editor({
        extensions: [StarterKit.configure({ link: false }), TrackedLink],
        content,
    });
    editor.commands.setTextSelection(2);

    const wrapper = mount(InsertLinkModal, {
        props: { isOpen: false, editor },
    });
    await wrapper.setProps({ isOpen: true });
    await nextTick();

    return wrapper;
};

const submit = async (wrapper: Awaited<ReturnType<typeof openModal>>) => {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text() === 'Insert link');

    await button?.trigger('click');
};

describe('InsertLinkModal link name', () => {
    afterEach(() => editor?.destroy());

    it('reads the URL and the name of the selected link', async () => {
        const wrapper = await openModal(
            '<p><a href="https://example.com/sale" data-bm-link="black-friday">Shop</a></p>',
        );

        expect(
            (wrapper.get('#link-url').element as HTMLInputElement).value,
        ).toBe('https://example.com/sale');
        expect(
            (wrapper.get('#link-name').element as HTMLInputElement).value,
        ).toBe('black-friday');
    });

    it('saves the normalized name on the link', async () => {
        const wrapper = await openModal(
            '<p><a href="https://example.com/sale">Shop</a></p>',
        );

        await wrapper.get('#link-name').setValue('Black Friday');
        expect(wrapper.text()).toContain('Saved as black-friday');

        await submit(wrapper);

        expect(editor.getHTML()).toContain('data-bm-link="black-friday"');
        expect(wrapper.emitted('update:isOpen')).toEqual([[false]]);
    });

    it('removes the name when the field is empty', async () => {
        const wrapper = await openModal(
            '<p><a href="https://example.com/sale" data-bm-link="black-friday">Shop</a></p>',
        );

        await wrapper.get('#link-name').setValue('');
        await submit(wrapper);

        expect(editor.getHTML()).not.toContain('data-bm-link');
        expect(editor.getHTML()).toContain('href="https://example.com/sale"');
    });
});
