import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@vue-flow/core', () => ({
    Handle: defineComponent({ name: 'Handle', template: '<span />' }),
    Position: { Bottom: 'bottom' },
}));

import { builderKey, type BuilderContext } from '../builderContext';
import TriggerNode from '../nodes/TriggerNode.vue';

const mountNode = (data: Record<string, unknown>) => {
    const updateNodeData = vi.fn();
    const builder: BuilderContext = {
        lists: [{ id: 1, name: 'Newsletter' }],
        tags: [],
        sequences: [],
        linkNames: ['black-friday', 'hero-cta'],
        isEditable: true,
        nodeContactsUrl: null,
        updateNodeData,
        confirmRemoveNode: vi.fn(),
        openAddStepModal: vi.fn(),
    };

    const wrapper = mount(TriggerNode, {
        // The node reads only id and data. Vue Flow passes the other NodeProps.
        props: { id: 'trigger-1', data } as never,
        global: { provide: { [builderKey as symbol]: builder } },
    });

    return { wrapper, updateNodeData };
};

describe('TriggerNode link clicked trigger', () => {
    it('offers the link clicked trigger type', () => {
        const { wrapper } = mountNode({
            trigger_type: 'contact_added_to_list',
            value: 1,
        });

        const labels = wrapper
            .get('select')
            .findAll('option')
            .map((option) => option.text());

        expect(labels).toContain('Link clicked');
    });

    it('clears the numeric value when the type changes to link clicked', async () => {
        const { wrapper, updateNodeData } = mountNode({
            trigger_type: 'contact_added_to_list',
            value: 1,
        });

        await wrapper.get('select').setValue('link_clicked');

        expect(updateNodeData).toHaveBeenCalledWith('trigger-1', {
            trigger_type: 'link_clicked',
            value: null,
        });
    });

    it('shows a text input with suggestions and stores the name as a string', async () => {
        const { wrapper, updateNodeData } = mountNode({
            trigger_type: 'link_clicked',
            value: 'black-friday',
        });

        const input = wrapper.get('input[type="text"]');
        expect((input.element as HTMLInputElement).value).toBe('black-friday');

        const suggestions = wrapper
            .findAll(`datalist#${input.attributes('list')} option`)
            .map((option) => option.attributes('value'));
        expect(suggestions).toEqual(['black-friday', 'hero-cta']);

        await input.setValue('Spring Sale');

        expect(updateNodeData).toHaveBeenLastCalledWith('trigger-1', {
            value: 'Spring Sale',
        });
    });

    it('shows the normalized name that the server matches', () => {
        const { wrapper } = mountNode({
            trigger_type: 'link_clicked',
            value: 'Spring Sale',
        });

        expect(wrapper.text()).toContain('Matches links named spring-sale');
    });

    it('asks for a link name while the trigger has none', () => {
        const { wrapper } = mountNode({
            trigger_type: 'link_clicked',
            value: null,
        });

        expect(wrapper.text()).toContain('Enter a link name.');
    });
});
