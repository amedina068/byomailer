import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';

import LinkClicksPanel from '../LinkClicksPanel.vue';

const summary = {
    delivered: 200,
    links: [
        {
            key: 'name:black-friday',
            name: 'black-friday',
            url: 'https://example.com/sale',
            unique_contacts: 12,
            total_clicks: 1500,
            click_rate: 6,
        },
        {
            key: 'url:https://example.com/blog',
            name: null,
            url: 'https://example.com/blog',
            unique_contacts: 3,
            total_clicks: 4,
            click_rate: null,
        },
    ],
};

const mountPanel = (
    linkClicks: typeof summary | undefined,
    activeKey: string | null = null,
) =>
    mount(LinkClicksPanel, {
        props: { linkClicks, activeKey, selectHint: 'Select a link.' },
        global: {
            stubs: {
                // Renders the fallback until the deferred prop has a value,
                // like the Inertia component.
                Deferred: defineComponent({
                    props: { data: { type: String, required: true } },
                    setup(_, { slots }) {
                        return () =>
                            linkClicks === undefined
                                ? slots.fallback?.()
                                : slots.default?.();
                    },
                }),
            },
        },
    });

describe('LinkClicksPanel', () => {
    it('shows skeleton rows while the deferred prop loads', () => {
        const wrapper = mountPanel(undefined);

        expect(wrapper.findAll('.animate-pulse')).toHaveLength(3);
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('shows the empty state with naming help when no link has clicks', () => {
        const wrapper = mountPanel({ delivered: 10, links: [] });

        expect(wrapper.text()).toContain('No link clicks yet');
        expect(wrapper.text()).toContain('data-bm-link');
        expect(wrapper.text()).toContain('Microsoft Safe Links');
    });

    it('renders one row for each link with the name first and numbers in mono', () => {
        const wrapper = mountPanel(summary);

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(2);

        const named = rows[0].findAll('td');
        expect(named[0].text()).toContain('black-friday');
        expect(named[0].get('a').attributes()).toMatchObject({
            href: 'https://example.com/sale',
            target: '_blank',
            rel: 'noopener noreferrer',
        });
        expect(named[1].text()).toBe((12).toLocaleString());
        expect(named[2].text()).toBe((1500).toLocaleString());
        expect(named[3].text()).toBe('6%');
        expect(named[1].classes()).toContain(
            'font-(family-name:--bm-font-mono)',
        );

        const unnamed = rows[1].findAll('td');
        expect(unnamed[0].text()).toBe('https://example.com/blog');
        expect(unnamed[0].find('a').exists()).toBe(false);
        expect(unnamed[3].text()).toBe('—');
    });

    it('emits the link key when a row is selected', async () => {
        const wrapper = mountPanel(summary);

        await wrapper.findAll('tbody tr')[1].trigger('click');
        await wrapper.findAll('tbody tr')[0].trigger('keydown.enter');

        expect(wrapper.emitted('select')).toEqual([
            ['url:https://example.com/blog'],
            ['name:black-friday'],
        ]);
    });

    it('does not select the row when the URL is opened', async () => {
        const wrapper = mountPanel(summary);

        await wrapper.findAll('tbody tr')[0].get('a').trigger('click');

        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('marks the active row', () => {
        const wrapper = mountPanel(summary, 'name:black-friday');

        const rows = wrapper.findAll('tbody tr');
        expect(rows[0].attributes('aria-pressed')).toBe('true');
        expect(rows[1].attributes('aria-pressed')).toBe('false');
    });
});
