import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import HtmlPreview from '@/components/templates/HtmlPreview.vue';

import ContactMailShow from '../contact-mails/Show.vue';

const storedHtml =
    '<p id="stored-content">Hello</p><img id="stored-image" src="x">';

const mountPage = () =>
    mount(ContactMailShow, {
        props: {
            mail: {
                id: 7,
                subject: 'Welcome',
                status: 'sent',
                html_content: storedHtml,
            },
        },
    });

describe('Contact mail page', () => {
    it('does not put the stored mail HTML into the page document', () => {
        const wrapper = mountPage();

        expect(wrapper.find('#stored-content').exists()).toBe(false);
        expect(wrapper.find('#stored-image').exists()).toBe(false);
    });

    it('shows the stored mail HTML in the sandboxed preview frame', () => {
        const wrapper = mountPage();
        const preview = wrapper.findComponent(HtmlPreview);

        expect(preview.exists()).toBe(true);
        expect(preview.props('html')).toBe(storedHtml);
    });
});
