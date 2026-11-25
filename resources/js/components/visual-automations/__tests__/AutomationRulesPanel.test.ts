import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AutomationRulesPanel from '../AutomationRulesPanel.vue';

const mountPanel = (props = {}) =>
    mount(AutomationRulesPanel, {
        props: {
            catalogs: {
                tags: [{ id: 1, name: 'Customer' }],
                lists: [],
                sequences: [],
            },
            disabled: false,
            errors: {},
            goals: [{ type: 'has_tag', value: 1 }],
            exitConditions: [],
            ...props,
        },
    });

describe('AutomationRulesPanel', () => {
    it('shows the goals and the exit conditions', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Goals');
        expect(wrapper.text()).toContain('Exit conditions');
        expect(wrapper.findAll('[data-test="rule"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('No exit conditions.');
        expect(wrapper.find('[data-test="rules-locked"]').exists()).toBe(false);
    });

    it('tells the user to pause a live automation', () => {
        const wrapper = mountPanel({ disabled: true });

        expect(wrapper.get('[data-test="rules-locked"]').text()).toBe(
            'Pause the automation to edit the goals and exit conditions.',
        );
    });

    it('shows each error under its list', () => {
        const wrapper = mountPanel({
            errors: {
                'workflow.nodes': 'A node error.',
                'workflow.exit_conditions.0.value':
                    'The exit condition value field is required.',
            },
        });

        const lists = wrapper.findAllComponents({ name: 'AutomationRuleList' });

        expect(lists[0].text()).not.toContain('required');
        expect(lists[1].text()).toContain(
            'The exit condition value field is required.',
        );
        expect(wrapper.text()).not.toContain('A node error.');
    });

    it('sends a new goal to the page', async () => {
        const wrapper = mountPanel({ goals: [] });

        await wrapper.get('[data-test="add-rule"]').trigger('click');

        expect(wrapper.emitted('update:goals')![0]).toEqual([
            [{ type: 'has_tag', value: 1 }],
        ]);
    });
});
