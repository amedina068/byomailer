import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import SequenceStatusBadge from '../SequenceStatusBadge.vue';

const statuses = [
    { value: 'draft', label: 'Draft' },
    { value: 'live', label: 'Live' },
];

describe('SequenceStatusBadge', () => {
    it('shows the label from the statuses prop', () => {
        const wrapper = mount(SequenceStatusBadge, {
            props: { status: 'live', statuses: [{ value: 'live', label: 'Running' }] },
        });

        expect(wrapper.text()).toBe('Running');
        expect(wrapper.attributes('style')).toContain('var(--bm-success)');
    });

    it('shows a draft status with the warning colors', () => {
        const wrapper = mount(SequenceStatusBadge, {
            props: { status: 'draft', statuses },
        });

        expect(wrapper.text()).toBe('Draft');
        expect(wrapper.attributes('style')).toContain('var(--bm-warning)');
    });

    it('shows the value of an unknown status', () => {
        const wrapper = mount(SequenceStatusBadge, {
            props: { status: 'archived', statuses },
        });

        expect(wrapper.text()).toBe('archived');
    });
});
