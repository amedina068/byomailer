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

import LinkClicks from '../LinkClicks.vue';

describe('Link clicks API reference', () => {
    const text = (): string =>
        mount(LinkClicks, { global: { stubs: { CodeBlock: true } } }).text();

    it('renders the link report endpoints of newsletters and steps', () => {
        expect(text()).toContain('/api/v1/newsletters/{newsletterId}/links');
        expect(text()).toContain(
            '/api/v1/newsletters/{newsletterId}/links/contacts',
        );
        expect(text()).toContain(
            '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/links',
        );
        expect(text()).toContain(
            '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/links/contacts',
        );
    });

    it('explains how to name a link', () => {
        expect(text()).toContain('data-bm-link');
    });
});
