import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ContactGrowthChart from '@/components/dashboard/ContactGrowthChart.vue';

const sampleData = [
    { day: 'Mon', new: 10, unsubscribed: 2, net: 8 },
    { day: 'Tue', new: 5, unsubscribed: 1, net: 4 },
];

describe('ContactGrowthChart', () => {
    it('renders the svg with responsive, shrinkable sizing', () => {
        const wrapper = mount(ContactGrowthChart, {
            props: { data: sampleData },
        });

        const svg = wrapper.find('svg');
        expect(svg.exists()).toBe(true);

        const classes = svg.classes();
        expect(classes).toContain('w-full');
        expect(classes).toContain('h-auto');
        expect(classes).toContain('max-w-full');

        // viewBox drives intrinsic sizing; no fixed width/height attrs that
        // would force the card past the mobile viewport.
        expect(svg.attributes('width')).toBeUndefined();
        expect(svg.attributes('height')).toBeUndefined();
        expect(svg.attributes('viewBox')).toBe('0 0 900 180');
    });

    it('root card cannot exceed its grid track width', () => {
        const wrapper = mount(ContactGrowthChart, {
            props: { data: sampleData },
        });

        const root = wrapper.find('div');
        expect(root.classes()).toContain('min-w-0');
        expect(root.classes()).toContain('w-full');
    });

    it('shows an empty state when there is no data', () => {
        const wrapper = mount(ContactGrowthChart, { props: { data: [] } });

        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.text()).toContain('No contact activity this week yet.');
    });
});
