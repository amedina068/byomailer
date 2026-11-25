import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const formErrors = reactive<Record<string, string>>({});

vi.mock('@inertiajs/vue3', () => ({
    Form: defineComponent({
        name: 'InertiaForm',
        setup(_, { slots }) {
            return () =>
                h(
                    'form',
                    slots.default?.({ errors: formErrors, processing: false }),
                );
        },
    }),
    usePage: () => ({ props: { flash: {} } }),
}));

vi.mock('@/components/tags/CreateTagModal.vue', () => ({
    default: defineComponent({ name: 'CreateTagModal', template: '<div />' }),
}));

import AutomationForm from '../AutomationForm.vue';

const baseProps = {
    action: '/automations',
    lists: [{ id: 1, name: 'Newsletter' }],
    tags: [{ id: 2, name: 'VIP' }],
    sequences: [],
    attributes: ['company', 'plan'],
};

const field = (wrapper: ReturnType<typeof mount>, name: string) =>
    wrapper.find(`[name="${name}"]`);

describe('AutomationForm attribute trigger', () => {
    it('shows the attribute fields when the condition is attribute_changed', async () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        await field(wrapper, 'condition[type]').setValue('attribute_changed');

        const attribute = field(wrapper, 'condition[attribute]');
        expect(attribute.exists()).toBe(true);
        expect(attribute.attributes('aria-label')).toBe('Attribute');
        expect(
            attribute.findAll('option').map((option) => option.text()),
        ).toEqual(['company', 'plan']);

        const operator = field(wrapper, 'condition[operator]');
        expect(operator.exists()).toBe(true);
        expect(operator.attributes('aria-label')).toBe('Change type');

        const value = field(wrapper, 'condition[value]');
        expect(value.element.tagName).toBe('INPUT');
        expect(value.attributes('aria-label')).toBe('Attribute value');

        expect(wrapper.text()).toContain(
            "Runs when a contact's attribute changes after this automation is live. Contacts that already have the value are not included.",
        );
        expect(wrapper.text()).toContain('Exact match, case-sensitive.');
    });

    it('hides the value input when the operator is changed', async () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        await field(wrapper, 'condition[type]').setValue('attribute_changed');
        await field(wrapper, 'condition[value]').setValue('pro');
        await field(wrapper, 'condition[operator]').setValue('changed');

        expect(field(wrapper, 'condition[value]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Exact match, case-sensitive.');

        await field(wrapper, 'condition[operator]').setValue('changed_to');

        expect(
            (field(wrapper, 'condition[value]').element as HTMLInputElement)
                .value,
        ).toBe('');
    });

    it('clears the attribute and operator when the type changes away', async () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        await field(wrapper, 'condition[type]').setValue('attribute_changed');
        await field(wrapper, 'condition[attribute]').setValue('plan');
        await field(wrapper, 'condition[operator]').setValue('changed');
        await field(wrapper, 'condition[type]').setValue('tag_added');

        expect(field(wrapper, 'condition[attribute]').exists()).toBe(false);
        expect(field(wrapper, 'condition[value]').element.tagName).toBe(
            'SELECT',
        );

        await field(wrapper, 'condition[type]').setValue('attribute_changed');

        expect(
            (
                field(wrapper, 'condition[attribute]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('company');
        expect(
            (field(wrapper, 'condition[operator]').element as HTMLSelectElement)
                .value,
        ).toBe('changed_to');
        expect(
            (field(wrapper, 'condition[value]').element as HTMLInputElement)
                .value,
        ).toBe('');
    });

    it('hides the option and shows a hint when the client has no attributes', () => {
        const wrapper = mount(AutomationForm, {
            props: { ...baseProps, attributes: [] },
        });

        expect(
            field(wrapper, 'condition[type]')
                .findAll('option')
                .map((option) => option.text()),
        ).not.toContain('Attribute changes');
        expect(wrapper.text()).toContain(
            'Attributes come from CSV imports, the API, or MCP. Add one to a contact to use this trigger.',
        );
    });

    it('does not show the empty hint when attributes exist', () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        expect(
            field(wrapper, 'condition[type]')
                .findAll('option')
                .map((option) => option.text()),
        ).toContain('Attribute changes');
        expect(wrapper.text()).not.toContain(
            'Attributes come from CSV imports',
        );
    });

    it('prefills the fields from a stored attribute condition', () => {
        const wrapper = mount(AutomationForm, {
            props: {
                ...baseProps,
                method: 'put' as const,
                automation: {
                    name: 'Upgrade',
                    condition: {
                        type: 'attribute_changed' as const,
                        attribute: 'plan',
                        operator: 'changed_to' as const,
                        value: 'pro',
                    },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                },
            },
        });

        expect(
            (field(wrapper, 'condition[type]').element as HTMLSelectElement)
                .value,
        ).toBe('attribute_changed');
        expect(
            (
                field(wrapper, 'condition[attribute]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('plan');
        expect(
            (field(wrapper, 'condition[operator]').element as HTMLSelectElement)
                .value,
        ).toBe('changed_to');
        expect(
            (field(wrapper, 'condition[value]').element as HTMLInputElement)
                .value,
        ).toBe('pro');
    });

    it('keeps a stored attribute that is no longer in the catalog', () => {
        const wrapper = mount(AutomationForm, {
            props: {
                ...baseProps,
                attributes: ['company'],
                method: 'put' as const,
                automation: {
                    name: 'Upgrade',
                    condition: {
                        type: 'attribute_changed' as const,
                        attribute: 'plan',
                        operator: 'changed_to' as const,
                        value: 'pro',
                    },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                },
            },
        });

        expect(
            (
                field(wrapper, 'condition[attribute]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('plan');
    });

    it('prefills a stored changed condition without a value input', () => {
        const wrapper = mount(AutomationForm, {
            props: {
                ...baseProps,
                automation: {
                    name: 'Any plan change',
                    condition: {
                        type: 'attribute_changed' as const,
                        attribute: 'plan',
                        operator: 'changed' as const,
                        value: null,
                    },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                },
            },
        });

        expect(
            (field(wrapper, 'condition[operator]').element as HTMLSelectElement)
                .value,
        ).toBe('changed');
        expect(field(wrapper, 'condition[value]').exists()).toBe(false);
    });

    it('shows validation errors for the attribute fields', async () => {
        formErrors['condition.attribute'] = 'Pick an attribute.';
        formErrors['condition.operator'] = 'Pick a change type.';
        formErrors['condition.value'] = 'Enter a value.';

        const wrapper = mount(AutomationForm, { props: baseProps });
        await field(wrapper, 'condition[type]').setValue('attribute_changed');

        expect(wrapper.text()).toContain('Pick an attribute.');
        expect(wrapper.text()).toContain('Pick a change type.');
        expect(wrapper.text()).toContain('Enter a value.');

        delete formErrors['condition.attribute'];
        delete formErrors['condition.operator'];
        delete formErrors['condition.value'];
    });
});

describe('AutomationForm link trigger', () => {
    it('shows a text input with link name suggestions', async () => {
        const wrapper = mount(AutomationForm, {
            props: { ...baseProps, linkNames: ['black-friday', 'hero-cta'] },
        });

        await field(wrapper, 'condition[type]').setValue('link_clicked');

        const value = field(wrapper, 'condition[value]');
        expect(value.element.tagName).toBe('INPUT');
        expect(value.attributes('aria-label')).toBe('Link name');
        expect((value.element as HTMLInputElement).value).toBe('');

        const listId = value.attributes('list');
        const suggestions = wrapper
            .findAll(`datalist#${listId} option`)
            .map((option) => option.attributes('value'));
        expect(suggestions).toEqual(['black-friday', 'hero-cta']);
    });

    it('shows the normalized name that the server matches', async () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        await field(wrapper, 'condition[type]').setValue('link_clicked');
        await field(wrapper, 'condition[value]').setValue('Black Friday');

        expect(wrapper.text()).toContain('Matches links named black-friday');
    });

    it('keeps the stored link name when editing', () => {
        const wrapper = mount(AutomationForm, {
            props: {
                ...baseProps,
                automation: {
                    name: 'Clickers',
                    condition: {
                        type: 'link_clicked' as const,
                        value: 'black-friday',
                    },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                },
            },
        });

        expect(
            (field(wrapper, 'condition[value]').element as HTMLInputElement)
                .value,
        ).toBe('black-friday');
    });

    it('selects the first list again when the type changes away', async () => {
        const wrapper = mount(AutomationForm, { props: baseProps });

        await field(wrapper, 'condition[type]').setValue('link_clicked');
        await field(wrapper, 'condition[value]').setValue('black-friday');
        await field(wrapper, 'condition[type]').setValue(
            'contact_added_to_list',
        );

        expect(
            (field(wrapper, 'condition[value]').element as HTMLSelectElement)
                .value,
        ).toBe('1');
    });
});
