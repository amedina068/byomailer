import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    Form: defineComponent({
        template:
            '<form><slot :errors="{}" :processing="false" :recently-successful="false" /></form>',
    }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/components/TiptapEditor.vue', () => ({
    default: defineComponent({ props: ['modelValue'], template: '<div />' }),
}));
vi.mock('@/components/templates/TemplatePickerModal.vue', () => ({
    default: defineComponent({
        name: 'TemplatePickerModal',
        props: ['isOpen'],
        emits: ['update:isOpen', 'select'],
        template: '<div />',
    }),
}));

import Edit from '../lists/Edit.vue';

const mountPage = () =>
    mount(Edit, {
        props: {
            list: {
                id: 7,
                uuid: 'list-uuid',
                name: 'Weekly',
                opt_in_enabled: true,
                message: {
                    subject: 'Confirm',
                    html_content: '<p><a href="{{opt_in_url}}">Confirm</a></p>',
                    email_identity_id: null,
                },
            },
            emailIdentities: [],
        },
    });

const htmlInput = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.get<HTMLInputElement>('input[name="opt_in_html_content"]').element
        .value;

describe('List edit page template picker', () => {
    it('puts the template html into the opt-in body, not the template object', async () => {
        const wrapper = mountPage();

        wrapper
            .findComponent({ name: 'TemplatePickerModal' })
            .vm.$emit('select', {
                id: 1,
                name: 'Plain',
                html_content: '<p>Plain template</p>',
                is_default: false,
            });
        await nextTick();

        expect(htmlInput(wrapper)).toBe('<p>Plain template</p>');
        expect(htmlInput(wrapper)).not.toContain('[object Object]');
    });

    it('keeps the current body with its confirm link inside a template slot', async () => {
        const wrapper = mountPage();

        wrapper
            .findComponent({ name: 'TemplatePickerModal' })
            .vm.$emit('select', {
                id: 2,
                name: 'Branded',
                html_content:
                    '<header>Brand</header>{{ content }}<footer>Footer</footer>',
                is_default: false,
            });
        await nextTick();

        expect(htmlInput(wrapper)).toBe(
            '<header>Brand</header><div data-slot="content"><p><a href="{{opt_in_url}}">Confirm</a></p></div><footer>Footer</footer>',
        );
    });
});
