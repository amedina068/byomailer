import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));

vi.mock('@/components/projects/ProjectBadge.vue', () => ({
    default: defineComponent({ name: 'ProjectBadge', template: '<span />' }),
}));

vi.mock('@/components/projects/ProjectQuickAssign.vue', () => ({
    default: defineComponent({
        name: 'ProjectQuickAssign',
        template: '<button type="button">Projects</button>',
    }),
}));

import AutomationRow from '../AutomationRow.vue';

const triggerText = (wrapper: ReturnType<typeof mount>): string => {
    const chip = wrapper
        .findAll('span')
        .find((span) => span.text().startsWith('plan changes'));

    return (chip?.findAll('span') ?? []).map((part) => part.text()).join(' ');
};

type AttributeCondition = {
    type: 'attribute_changed';
    attribute: string;
    operator: 'changed_to' | 'changed';
    value: string | null;
};

const mountRow = (condition: AttributeCondition) =>
    mount(AutomationRow, {
        props: {
            automation: {
                id: 1,
                name: 'Upgrade',
                type: 'simple',
                condition,
                actions: [{ type: 'add_tag' as const, value: 2 }],
                is_live: true,
            },
            editUrl: '/automations/1/edit',
            listNames: {},
            tagNames: { '2': 'VIP' },
            sequenceNames: {},
        },
    });

describe('AutomationRow attribute trigger chip', () => {
    it('labels a changed_to condition with the value', () => {
        const wrapper = mountRow({
            type: 'attribute_changed',
            attribute: 'plan',
            operator: 'changed_to',
            value: 'pro',
        });

        expect(triggerText(wrapper)).toBe('plan changes to "pro"');
    });

    it('labels a changed condition without a value', () => {
        const wrapper = mountRow({
            type: 'attribute_changed',
            attribute: 'plan',
            operator: 'changed',
            value: null,
        });

        expect(triggerText(wrapper)).toBe('plan changes');
    });
});

describe('AutomationRow link trigger chip', () => {
    it('labels a link_clicked condition with the link name', () => {
        const wrapper = mount(AutomationRow, {
            props: {
                automation: {
                    id: 2,
                    name: 'Black Friday clickers',
                    type: 'simple',
                    condition: {
                        type: 'link_clicked' as const,
                        value: 'black-friday',
                    },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                    is_live: true,
                },
                editUrl: '/automations/2/edit',
                listNames: {},
                tagNames: { '2': 'VIP' },
                sequenceNames: {},
            },
        });

        const chip = wrapper
            .findAll('span')
            .find((span) => span.text().startsWith('Clicks link'));

        expect(
            (chip?.findAll('span') ?? []).map((part) => part.text()).join(' '),
        ).toBe('Clicks link "black-friday"');
    });
});

describe('AutomationRow keyboard', () => {
    beforeEach(() => visit.mockReset());

    const mountWithProjects = () =>
        mount(AutomationRow, {
            props: {
                automation: {
                    id: 1,
                    name: 'Upgrade',
                    type: 'simple',
                    condition: { type: 'tag_added' as const, value: 2 },
                    actions: [{ type: 'add_tag' as const, value: 2 }],
                    is_live: true,
                },
                editUrl: '/automations/1/edit',
                listNames: {},
                tagNames: { '2': 'VIP' },
                sequenceNames: {},
                projects: [{ id: 1, name: 'Launch' }],
                projectIds: [],
                syncUrl: '/automations/1/projects',
            },
        });

    it('opens the automation when Enter is pressed on the row', async () => {
        const wrapper = mountWithProjects();

        await wrapper.find('[role="button"]').trigger('keydown.enter');

        expect(visit).toHaveBeenCalledWith('/automations/1/edit');
    });

    it('does not open the automation when Enter is pressed on a nested control', async () => {
        const wrapper = mountWithProjects();

        await wrapper.find('button').trigger('keydown.enter');

        expect(visit).not.toHaveBeenCalled();
    });

    it('shows a visible focus ring', () => {
        const row = mountWithProjects().find('[role="button"]');

        expect(row.classes()).toContain('focus-visible:ring-2');
    });
});
