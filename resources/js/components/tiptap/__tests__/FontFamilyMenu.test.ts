import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import FontFamilyMenu from '../FontFamilyMenu.vue';
import { FONT_FAMILIES } from '../typography';

const ARIAL = FONT_FAMILIES.find((f) => f.label === 'Arial')!.value!;
const GEORGIA = FONT_FAMILIES.find((f) => f.label === 'Georgia')!.value!;

interface MockEditor {
    setFontFamily: ReturnType<typeof vi.fn>;
    unsetFontFamily: ReturnType<typeof vi.fn>;
    currentFamily: string | null;
    chain: () => MockEditor;
    focus: () => MockEditor;
    run: () => boolean;
    getAttributes: (name: string) => Record<string, unknown>;
}

const buildMockEditor = (currentFamily: string | null = null): MockEditor => {
    const m: Partial<MockEditor> = { currentFamily };
    m.chain = () => m as MockEditor;
    m.focus = () => m as MockEditor;
    m.run = () => true;
    m.setFontFamily = vi.fn(() => m as MockEditor);
    m.unsetFontFamily = vi.fn(() => m as MockEditor);
    m.getAttributes = (name: string) =>
        name === 'textStyle' ? { fontFamily: m.currentFamily } : {};
    return m as MockEditor;
};

describe('FontFamilyMenu', () => {
    let editor: MockEditor;

    beforeEach(() => {
        editor = buildMockEditor();
    });

    it('renders all 10 family options when opened', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const options = wrapper.findAll('[role="option"]');
        expect(options).toHaveLength(10);
        expect(options[0].text()).toBe('Default');
        expect(options[1].text()).toBe('System UI');
        expect(options[2].text()).toBe('Arial');
    });

    it('calls setFontFamily with the correct stack when an option is clicked', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const arialOption = wrapper
            .findAll('[role="option"]')
            .find((o) => o.text() === 'Arial')!;
        await arialOption.trigger('click');

        expect(editor.setFontFamily).toHaveBeenCalledWith(ARIAL);
        expect(editor.unsetFontFamily).not.toHaveBeenCalled();
    });

    it('calls unsetFontFamily when "Default" is clicked', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const def = wrapper
            .findAll('[role="option"]')
            .find((o) => o.text() === 'Default')!;
        await def.trigger('click');

        expect(editor.unsetFontFamily).toHaveBeenCalled();
        expect(editor.setFontFamily).not.toHaveBeenCalled();
    });

    it('previews each non-default option in its own font-family', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const georgia = wrapper
            .findAll('[role="option"]')
            .find((o) => o.text() === 'Georgia')!;
        const style = georgia.attributes('style') ?? '';
        expect(style).toContain('font-family');
        expect(style).toContain('Georgia');

        const def = wrapper.findAll('[role="option"]').find((o) => o.text() === 'Default')!;
        expect(def.attributes('style') ?? '').not.toContain('font-family');
    });

    it('reflects current family on the trigger label', () => {
        const wrapper = mount(FontFamilyMenu, {
            props: { editor: buildMockEditor(GEORGIA) as never, disabled: false },
        });

        expect(wrapper.find('button').text()).toContain('Georgia');
    });

    it('shows "Default" on the trigger when no family is set (the null-valued option)', () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });

        expect(wrapper.find('button').text()).toContain('Default');
    });

    it('always prefixes the trigger with "Font:" so the dropdown purpose is obvious', () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });

        expect(wrapper.find('button').text()).toMatch(/^\s*Font:/);
    });

    it('disables trigger when disabled prop is true', () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: true } });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('closes menu after selecting an option', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');
        expect(wrapper.findAll('[role="option"]').length).toBe(10);

        const arial = wrapper
            .findAll('[role="option"]')
            .find((o) => o.text() === 'Arial')!;
        await arial.trigger('click');

        expect(wrapper.findAll('[role="option"]').length).toBe(0);
    });

    it('toggling the trigger closes an open menu', async () => {
        const wrapper = mount(FontFamilyMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');
        expect(wrapper.findAll('[role="option"]').length).toBe(10);
        await wrapper.find('button').trigger('click');
        expect(wrapper.findAll('[role="option"]').length).toBe(0);
    });
});
