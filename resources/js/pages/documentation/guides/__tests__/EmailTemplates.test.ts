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

import EmailTemplates from '../EmailTemplates.vue';

describe('Email templates guide', () => {
    it('shows the Liquid tags and the rules of the sandbox', () => {
        const text = mount(EmailTemplates).text();

        expect(text).toContain('{% if plan == "pro" %}');
        expect(text).toContain('{% for item in interests %}');
        expect(text).toContain('output is HTML-escaped');
        expect(text).toContain(
            'Content that uses only {{ variable }} placeholders sends the same as before.',
        );
    });
});
