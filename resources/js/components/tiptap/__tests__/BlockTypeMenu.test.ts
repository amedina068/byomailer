import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BlockTypeMenu from '../BlockTypeMenu.vue';

interface MockEditor {
    setParagraph: ReturnType<typeof vi.fn>;
    setHeading: ReturnType<typeof vi.fn>;
    activeBlock: 'paragraph' | 'h1' | 'h2' | 'h3' | null;
    chain: () => MockEditor;
    focus: () => MockEditor;
    run: () => boolean;
    isActive: (name: string, attrs?: Record<string, unknown>) => boolean;
}

const buildMockEditor = (active: MockEditor['activeBlock'] = 'paragraph'): MockEditor => {
    const m: Partial<MockEditor> = { activeBlock: active };
    m.chain = () => m as MockEditor;
    m.focus = () => m as MockEditor;
    m.run = () => true;
    m.setParagraph = vi.fn(() => m as MockEditor);
    m.setHeading = vi.fn(() => m as MockEditor);
    m.isActive = (name: string, attrs?: Record<string, unknown>) => {
        if (name === 'paragraph') {
            return m.activeBlock === 'paragraph';
        }
        if (name === 'heading' && attrs && typeof attrs.level === 'number') {
            return m.activeBlock === `h${attrs.level}`;
        }
        return false;
    };
    return m as MockEditor;
};

describe('BlockTypeMenu', () => {
    let editor: MockEditor;

    beforeEach(() => {
        editor = buildMockEditor();
    });

    it('renders Paragraph + H1 + H2 + H3 options', async () => {
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const options = wrapper.findAll('[role="option"]');
        expect(options).toHaveLength(4);
        expect(options[0].text()).toContain('Paragraph');
        expect(options[1].text()).toContain('Heading 1');
        expect(options[2].text()).toContain('Heading 2');
        expect(options[3].text()).toContain('Heading 3');
    });

    it('calls setParagraph (not toggleParagraph) when Paragraph is clicked', async () => {
        editor = buildMockEditor('h1');
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const para = wrapper.findAll('[role="option"]').find((o) => o.text().includes('Paragraph'))!;
        await para.trigger('click');

        expect(editor.setParagraph).toHaveBeenCalled();
        expect(editor.setHeading).not.toHaveBeenCalled();
    });

    it('calls setHeading with the right level for H1, H2, H3', async () => {
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const h2 = wrapper.findAll('[role="option"]').find((o) => o.text().includes('Heading 2'))!;
        await h2.trigger('click');

        expect(editor.setHeading).toHaveBeenCalledWith({ level: 2 });
    });

    it('reflects active block on the trigger label', () => {
        const wrapper = mount(BlockTypeMenu, {
            props: { editor: buildMockEditor('h2') as never, disabled: false },
        });

        expect(wrapper.find('button').text()).toContain('Heading 2');
    });

    it('shows Paragraph as the default active block', () => {
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: false } });

        expect(wrapper.find('button').text()).toContain('Paragraph');
    });

    it('disables when disabled prop is true', () => {
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: true } });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('closes the menu after selecting an option', async () => {
        const wrapper = mount(BlockTypeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');
        expect(wrapper.findAll('[role="option"]')).toHaveLength(4);

        const h1 = wrapper.findAll('[role="option"]').find((o) => o.text().includes('Heading 1'))!;
        await h1.trigger('click');

        expect(wrapper.findAll('[role="option"]')).toHaveLength(0);
    });

    it('falls back to "Paragraph" label when nothing is active', () => {
        const wrapper = mount(BlockTypeMenu, {
            props: { editor: buildMockEditor(null) as never, disabled: false },
        });

        expect(wrapper.find('button').text()).toContain('Paragraph');
    });
});
