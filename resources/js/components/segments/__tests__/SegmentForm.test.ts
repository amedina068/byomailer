import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';

const { post } = vi.hoisted(() => ({
    post: vi.fn(() => Promise.resolve({ data: { count: 1234 } })),
}));

vi.mock('axios', () => ({ default: { post } }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            processing: false,
            errors: {},
            transform() {
                return this;
            },
            put: vi.fn(),
            post: vi.fn(),
        }),
}));

vi.mock('@/routes/newsletters/contacts', () => ({
    preview: () => ({ url: '/newsletters/contacts/preview' }),
}));

vi.mock('@/routes/segments', () => ({
    store: () => ({ url: '/segments' }),
    update: (id: number) => ({ url: `/segments/${id}` }),
}));

vi.mock('@/components/newsletters/FiltersForm.vue', () => ({
    default: defineComponent({ name: 'FiltersForm', template: '<div />' }),
}));

import SegmentForm from '../SegmentForm.vue';

const baseProps = {
    lists: [],
    tags: [],
    attributeKeys: [],
};

afterEach(() => {
    post.mockClear();
});

describe('SegmentForm audience count', () => {
    it('fetches and renders the matching contact count on mount', async () => {
        const wrapper = mount(SegmentForm, { props: baseProps });

        await flushPromises();

        expect(post).toHaveBeenCalledWith('/newsletters/contacts/preview', {
            filters: [],
        });
        expect(wrapper.text()).toContain('This segment currently matches');
        expect(wrapper.text()).toContain('1,234');
        expect(wrapper.text()).toContain('contacts');
    });

    it('uses the singular noun for a single matching contact', async () => {
        post.mockResolvedValueOnce({ data: { count: 1 } });

        const wrapper = mount(SegmentForm, { props: baseProps });

        await flushPromises();

        expect(wrapper.text()).toContain('1 contact.');
    });
});
