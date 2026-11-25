import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ template: '<a><slot /></a>' }),
}));
vi.mock('@/components/newsletters/FiltersForm.vue', () => ({
    default: defineComponent({ template: '<div />' }),
}));

import TargetingForm from '../TargetingForm.vue';

describe('Targeting form', () => {
    it('shows the error of the segment field', () => {
        const wrapper = mount(TargetingForm, {
            props: {
                segments: [
                    { id: 3, name: 'Buyers', description: null, filters: [] },
                ],
                segmentId: 3,
                lists: [],
                tags: [],
                attributeKeys: [],
                processing: false,
                canEdit: true,
                segmentError: 'The selected segment is invalid.',
            },
        });

        expect(wrapper.text()).toContain('The selected segment is invalid.');
    });
});
