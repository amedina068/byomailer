import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import FontSizeMenu from '../FontSizeMenu.vue';
import { FONT_SIZES } from '../typography';

interface MockEditor {
    setFontSize: ReturnType<typeof vi.fn>;
    unsetFontSize: ReturnType<typeof vi.fn>;
    currentSize: string | null;
    chain: () => MockEditor;
    focus: () => MockEditor;
    run: () => boolean;
    getAttributes: (name: string) => Record<string, unknown>;
}

const buildMockEditor = (currentSize: string | null = null): MockEditor => {
    const m: Partial<MockEditor> = { currentSize };
    m.chain = () => m as MockEditor;
    m.focus = () => m as MockEditor;
    m.run = () => true;
    m.setFontSize = vi.fn(() => m as MockEditor);
    m.unsetFontSize = vi.fn(() => m as MockEditor);
    m.getAttributes = (name: string) =>
        name === 'textStyle' ? { fontSize: m.currentSize } : {};
    return m as MockEditor;
};

describe('FontSizeMenu', () => {
    let editor: MockEditor;

    beforeEach(() => {
        editor = buildMockEditor();
    });

    it('renders Default + 6 named sizes when opened', async () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const options = wrapper.findAll('[role="option"]');
        expect(options).toHaveLength(7);
        expect(options[0].text()).toContain('Default');
        expect(options[1].text()).toContain('XS');
        expect(options[1].text()).toContain('12');
    });

    it('calls setFontSize with px string when a size is selected', async () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const lOption = wrapper
            .findAll('[role="option"]')
            .find((o) => o.text().includes('L') && o.text().includes('18'))!;
        await lOption.trigger('click');

        expect(editor.setFontSize).toHaveBeenCalledWith('18px');
    });

    it('calls unsetFontSize when "Default" is selected', async () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const def = wrapper.findAll('[role="option"]').find((o) => o.text().includes('Default'))!;
        await def.trigger('click');

        expect(editor.unsetFontSize).toHaveBeenCalled();
        expect(editor.setFontSize).not.toHaveBeenCalled();
    });

    it('reflects current size on the trigger', () => {
        const wrapper = mount(FontSizeMenu, {
            props: { editor: buildMockEditor('24px') as never, disabled: false },
        });

        expect(wrapper.find('button').text()).toContain('XL');
    });

    it('shows "Default" on trigger when no size is set', () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });

        expect(wrapper.find('button').text()).toContain('Default');
    });

    it('always prefixes the trigger with "Size:" so the dropdown purpose is obvious', () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });

        expect(wrapper.find('button').text()).toMatch(/^\s*Size:/);
    });

    it('renders px values in JetBrains Mono', async () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const numericLabels = wrapper.findAll('[data-size-px]');
        expect(numericLabels.length).toBe(6);
        for (const el of numericLabels) {
            expect(el.classes()).toContain('font-mono');
        }
    });

    it('disables when disabled prop is true', () => {
        const wrapper = mount(FontSizeMenu, { props: { editor: editor as never, disabled: true } });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('FONT_SIZES px values map to scale labels', () => {
        expect(FONT_SIZES.xs).toBe(12);
        expect(FONT_SIZES['2xl']).toBe(32);
    });
});
