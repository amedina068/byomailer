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
        DropdownMenuContent: defineComponent({
            name: 'DropdownMenuContentStub',
            props: { align: { type: String, default: 'start' } },
            template: '<div data-testid="content" :data-align="align"><slot /></div>',
        }),
        DropdownMenuRadioGroup: defineComponent({
            name: 'DropdownMenuRadioGroupStub',
            props: { modelValue: { type: String, default: 'all' } },
            emits: ['update:modelValue'],
            template: '<div role="radiogroup"><slot /></div>',
        }),
        DropdownMenuRadioItem: defineComponent({
            name: 'DropdownMenuRadioItemStub',
            props: { value: { type: String, required: true } },
            template: '<div role="menuitemradio" :data-value="value"><slot /></div>',
        }),
    };
});

import ProjectFilterDropdown from '../ProjectFilterDropdown.vue';

const projects = [
    { id: 1, name: 'Onboarding' },
    { id: 2, name: 'Product Launch' },
];

function mountDropdown(modelValue = 'all', align?: 'start' | 'end') {
    return mount(ProjectFilterDropdown, {
        props: { projects, modelValue, align },
    });
}

describe('ProjectFilterDropdown', () => {
    it('renders nothing when there are no projects', () => {
        const wrapper = mount(ProjectFilterDropdown, {
            props: { projects: [], modelValue: 'all' },
        });

        expect(wrapper.find('[data-testid="trigger"]').exists()).toBe(false);
    });

    it('shows the All projects label by default', () => {
        const wrapper = mountDropdown();

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            'All projects',
        );
    });

    it('shows the selected project name in the trigger', () => {
        const wrapper = mountDropdown('2');

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            'Product Launch',
        );
    });

    it('falls back to All projects for an unknown project id', () => {
        const wrapper = mountDropdown('999');

        expect(wrapper.get('[data-testid="trigger"]').text()).toContain(
            'All projects',
        );
    });

    it('renders an option per project plus the all option', () => {
        const wrapper = mountDropdown();
        const items = wrapper.findAll('[role="menuitemradio"]');

        expect(items.map((item) => item.attributes('data-value'))).toEqual([
            'all',
            '1',
            '2',
        ]);
        expect(wrapper.text()).toContain('Onboarding');
        expect(wrapper.text()).toContain('Product Launch');
    });

    it('passes the align prop through to the dropdown content', () => {
        const wrapper = mountDropdown('all', 'end');

        expect(wrapper.get('[data-testid="content"]').attributes('data-align')).toBe(
            'end',
        );
    });

    it('emits update:modelValue when a project is picked', () => {
        const wrapper = mountDropdown();

        wrapper
            .findComponent({ name: 'DropdownMenuRadioGroupStub' })
            .vm.$emit('update:modelValue', '2');

        expect(wrapper.emitted('update:modelValue')).toEqual([['2']]);
    });

    it('ignores non-string values from the radio group', () => {
        const wrapper = mountDropdown();

        wrapper
            .findComponent({ name: 'DropdownMenuRadioGroupStub' })
            .vm.$emit('update:modelValue', 2);

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
