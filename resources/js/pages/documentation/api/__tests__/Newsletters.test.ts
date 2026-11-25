import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    usePage: () => ({ props: { appUrl: 'https://byomailer.example' } }),
}));
vi.mock('@/layouts/documentation/DocumentationLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import Newsletters from '../Newsletters.vue';

describe('Newsletters API reference', () => {
    it('renders the delete endpoint, which has an empty 204 response', () => {
        const text = mount(Newsletters, {
            global: { stubs: { CodeBlock: true } },
        }).text();

        expect(text).toContain('Delete a draft or scheduled newsletter.');
    });
});
