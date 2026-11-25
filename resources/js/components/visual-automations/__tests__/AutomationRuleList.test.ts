import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AutomationRuleList from '../AutomationRuleList.vue';
import type { AutomationRule, ResourceCatalogs } from '../types';

const catalogs: ResourceCatalogs = {
    tags: [
        { id: 1, name: 'Customer' },
        { id: 2, name: 'Lead' },
    ],
    lists: [{ id: 7, name: 'Newsletter' }],
    topics: [],
    sequences: [{ id: 9, name: 'Onboarding' }],
};

const mountList = (rules: AutomationRule[], props = {}) => {
    const wrapper = mount(AutomationRuleList, {
        props: {
            label: 'Goals',
            description: 'A contact that meets a goal leaves.',
            emptyText: 'No goals.',
            catalogs,
            modelValue: rules,
            'onUpdate:modelValue': (value: AutomationRule[]) =>
                wrapper.setProps({ modelValue: value }),
            ...props,
        },
    });

    return wrapper;
};

const emitted = (wrapper: ReturnType<typeof mountList>): AutomationRule[] =>
    wrapper.emitted('update:modelValue')!.at(-1)![0] as AutomationRule[];

describe('AutomationRuleList', () => {
    it('shows the empty text when the list has no rules', () => {
        const wrapper = mountList([]);

        expect(wrapper.text()).toContain('No goals.');
        expect(wrapper.findAll('[data-test="rule"]')).toHaveLength(0);
    });

    it('adds a rule with the first type and its first option', async () => {
        const wrapper = mountList([]);

        await wrapper.get('[data-test="add-rule"]').trigger('click');

        expect(emitted(wrapper)).toEqual([{ type: 'has_tag', value: 1 }]);
        expect(wrapper.findAll('[data-test="rule"]')).toHaveLength(1);
    });

    it('offers only the types that have options', () => {
        const wrapper = mountList([{ type: 'has_tag', value: 1 }]);

        const types = wrapper
            .findAll('[data-test="rule-type"] option')
            .map((option) => option.text());

        expect(types).toEqual(['Has tag', 'In list', 'In sequence']);
    });

    it('selects the first option of a new type', async () => {
        const wrapper = mountList([{ type: 'has_tag', value: 2 }]);

        await wrapper.get('[data-test="rule-type"]').setValue('in_sequence');

        expect(emitted(wrapper)).toEqual([{ type: 'in_sequence', value: 9 }]);
    });

    it('changes the value of a rule', async () => {
        const wrapper = mountList([{ type: 'has_tag', value: 1 }]);

        await wrapper.get('[data-test="rule-value"]').setValue('2');

        expect(emitted(wrapper)).toEqual([{ type: 'has_tag', value: 2 }]);
    });

    it('removes a rule', async () => {
        const wrapper = mountList([
            { type: 'has_tag', value: 1 },
            { type: 'in_list', value: 7 },
        ]);

        await wrapper.findAll('[data-test="remove-rule"]')[0].trigger('click');

        expect(emitted(wrapper)).toEqual([{ type: 'in_list', value: 7 }]);
    });

    it('locks the rules when the list is disabled', () => {
        const wrapper = mountList([{ type: 'has_tag', value: 1 }], {
            disabled: true,
        });

        expect(
            wrapper.get('[data-test="add-rule"]').attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper.get('[data-test="rule-type"]').attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper.get('[data-test="remove-rule"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('stops at ten rules', () => {
        const wrapper = mountList(
            Array.from({ length: 10 }, () => ({
                type: 'has_tag' as const,
                value: 1,
            })),
        );

        expect(
            wrapper.get('[data-test="add-rule"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('shows the error', () => {
        const wrapper = mountList([], {
            error: 'One or more selected tags do not belong to your account.',
        });

        expect(wrapper.text()).toContain(
            'One or more selected tags do not belong to your account.',
        );
    });
});
