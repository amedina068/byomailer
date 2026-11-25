import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ColorMenu from '../ColorMenu.vue';
import { RECENT_COLORS_KEY } from '../recentColors';

interface MockEditor {
    setColor: ReturnType<typeof vi.fn>;
    unsetColor: ReturnType<typeof vi.fn>;
    currentColor: string | null;
    chain: () => MockEditor;
    focus: () => MockEditor;
    run: () => boolean;
    getAttributes: (name: string) => Record<string, unknown>;
}

const buildMockEditor = (currentColor: string | null = null): MockEditor => {
    const m: Partial<MockEditor> = { currentColor };
    m.chain = () => m as MockEditor;
    m.focus = () => m as MockEditor;
    m.run = () => true;
    m.setColor = vi.fn(() => m as MockEditor);
    m.unsetColor = vi.fn(() => m as MockEditor);
    m.getAttributes = (name: string) =>
        name === 'textStyle' ? { color: m.currentColor } : {};
    return m as MockEditor;
};

describe('ColorMenu', () => {
    let editor: MockEditor;

    beforeEach(() => {
        localStorage.clear();
        editor = buildMockEditor();
    });

    it('opens the popover on trigger click', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        expect(wrapper.find('[data-color-popover]').exists()).toBe(false);

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('[data-color-popover]').exists()).toBe(true);
    });

    it('applies a valid hex color via the text input', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const input = wrapper.find('input[type="text"]');
        await input.setValue('#c8ff00');
        await input.trigger('keydown.enter');

        expect(editor.setColor).toHaveBeenCalledWith('#c8ff00');
    });

    it('rejects invalid hex via the text input and shows an error', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const input = wrapper.find('input[type="text"]');
        await input.setValue('not-a-color');
        await input.trigger('keydown.enter');

        expect(editor.setColor).not.toHaveBeenCalled();
        expect(wrapper.text()).toMatch(/invalid|hex/i);
    });

    it('normalizes hex without leading hash', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const input = wrapper.find('input[type="text"]');
        await input.setValue('AABBCC');
        await input.trigger('keydown.enter');

        expect(editor.setColor).toHaveBeenCalledWith('#aabbcc');
    });

    it('applies color from native color input on change (not on input drag)', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const colorInput = wrapper.find('input[type="color"]');
        await colorInput.setValue('#123456');
        await colorInput.trigger('change');

        expect(editor.setColor).toHaveBeenCalledWith('#123456');
    });

    it('keeps the popover open after picking from the native color input', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('[data-color-popover]').exists()).toBe(true);

        const colorInput = wrapper.find('input[type="color"]');
        await colorInput.setValue('#123456');
        await colorInput.trigger('change');

        expect(wrapper.find('[data-color-popover]').exists()).toBe(true);
    });

    it('closes after pressing Enter on the hex text input (explicit commit)', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const input = wrapper.find('input[type="text"]');
        await input.setValue('#c8ff00');
        await input.trigger('keydown.enter');

        expect(wrapper.find('[data-color-popover]').exists()).toBe(false);
    });

    it('shows recent swatches and applies on click', async () => {
        localStorage.setItem(RECENT_COLORS_KEY, JSON.stringify(['#aaaaaa', '#bbbbbb']));
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const swatches = wrapper.findAll('[data-recent-swatch]');
        expect(swatches).toHaveLength(2);

        await swatches[0].trigger('click');

        expect(editor.setColor).toHaveBeenCalledWith('#aaaaaa');
    });

    it('Reset button calls unsetColor and closes the popover', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        await wrapper.find('[data-reset]').trigger('click');

        expect(editor.unsetColor).toHaveBeenCalled();
        expect(wrapper.find('[data-color-popover]').exists()).toBe(false);
    });

    it('persists chosen color to recent swatches', async () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const input = wrapper.find('input[type="text"]');
        await input.setValue('#c8ff00');
        await input.trigger('keydown.enter');

        const stored = JSON.parse(localStorage.getItem(RECENT_COLORS_KEY) ?? '[]') as string[];
        expect(stored).toContain('#c8ff00');
    });

    it('reflects current color on the trigger swatch', () => {
        const wrapper = mount(ColorMenu, {
            props: { editor: buildMockEditor('#c8ff00') as never, disabled: false },
        });

        const swatch = wrapper.find('[data-current-swatch]');
        expect(swatch.attributes('style') ?? '').toContain('#c8ff00');
    });

    it('shows BLACK on the trigger swatch when no color is set', () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });

        const swatch = wrapper.find('[data-current-swatch]');
        const style = swatch.attributes('style') ?? '';
        expect(style.toLowerCase()).toMatch(/#000(000)?|rgb\(\s*0\s*,\s*0\s*,\s*0\s*\)|black/);
    });

    it('disables when disabled prop is true', () => {
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: true } });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('caps recent swatches at 5', async () => {
        localStorage.setItem(
            RECENT_COLORS_KEY,
            JSON.stringify(['#111111', '#222222', '#333333', '#444444', '#555555']),
        );
        const wrapper = mount(ColorMenu, { props: { editor: editor as never, disabled: false } });
        await wrapper.find('button').trigger('click');

        const swatches = wrapper.findAll('[data-recent-swatch]');
        expect(swatches).toHaveLength(5);

        const input = wrapper.find('input[type="text"]');
        await input.setValue('#c8ff00');
        await input.trigger('keydown.enter');

        const stored = JSON.parse(localStorage.getItem(RECENT_COLORS_KEY) ?? '[]') as string[];
        expect(stored).toHaveLength(5);
        expect(stored[0]).toBe('#c8ff00');
        expect(stored).not.toContain('#555555');
    });
});
