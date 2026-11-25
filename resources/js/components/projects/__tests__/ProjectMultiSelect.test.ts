import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (name: string) =>
        defineComponent({ name, template: '<div><slot /></div>' });

    return {
        DropdownMenu: passthrough('DropdownMenuStub'),
        DropdownMenuTrigger: defineComponent({
            name: 'DropdownMenuTriggerStub',
            props: { asChild: { type: Boolean, default: false } },
            template: '<div data-testid="trigger"><slot /></div>',
        }),
        DropdownMenuContent: passthrough('DropdownMenuContentStub'),
        DropdownMenuLabel: passthrough('DropdownMenuLabelStub'),
        DropdownMenuSeparator: defineComponent({
            name: 'DropdownMenuSeparatorStub',
            template: '<hr />',
        }),
        DropdownMenuCheckboxItem: defineComponent({
            name: 'DropdownMenuCheckboxItemStub',
            props: { checked: { type: Boolean, default: false } },
            emits: ['select'],
            template:
                '<div role="menuitemcheckbox" :aria-checked="checked ? \'true\' : \'false\'" @click="$emit(\'select\', $event)"><slot /></div>',
        }),
    };
});

import ProjectMultiSelect from '../ProjectMultiSelect.vue';

const options = [
    { id: 1, name: 'Onboarding' },
    { id: 2, name: 'Product Launch' },
    { id: 3, name: 'Weekly Digest' },
];

function mountSelect(modelValue: number[] = [], label?: string) {
    return mount(ProjectMultiSelect, {
        props: { modelValue, options, label },
    });
}

describe('ProjectMultiSelect', () => {
    it('renders every option name', () => {
        const wrapper = mountSelect();

        for (const option of options) {
            expect(wrapper.text()).toContain(option.name);
        }
    });

    it('shows the default label when nothing is selected', () => {
        const wrapper = mountSelect([]);

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            'Projects',
        );
    });

    it('shows a custom label when provided', () => {
        const wrapper = mountSelect([], 'Filter projects');

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            'Filter projects',
        );
    });

    it('shows a singular count label with one selection', () => {
        const wrapper = mountSelect([1]);

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            '1 project',
        );
    });

    it('shows a plural count label with multiple selections', () => {
        const wrapper = mountSelect([1, 3]);

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            '2 projects',
        );
    });

    it('emits update:modelValue with the id added when toggling an unselected option', async () => {
        const wrapper = mountSelect([1]);

        await wrapper.findAll('[role="menuitemcheckbox"]')[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[[1, 2]]]);
    });

    it('emits update:modelValue with the id removed when toggling a selected option', async () => {
        const wrapper = mountSelect([1, 2]);

        await wrapper.findAll('[role="menuitemcheckbox"]')[0].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[[2]]]);
    });

    it('does not mutate the modelValue prop when toggling', async () => {
        const modelValue = [1];
        const wrapper = mount(ProjectMultiSelect, {
            props: { modelValue, options },
        });

        await wrapper.findAll('[role="menuitemcheckbox"]')[1].trigger('click');

        expect(modelValue).toEqual([1]);
    });

    it('marks selected options as checked', () => {
        const wrapper = mountSelect([2]);
        const items = wrapper.findAll('[role="menuitemcheckbox"]');

        expect(items[0].attributes('aria-checked')).toBe('false');
        expect(items[1].attributes('aria-checked')).toBe('true');
        expect(items[2].attributes('aria-checked')).toBe('false');
    });
});
