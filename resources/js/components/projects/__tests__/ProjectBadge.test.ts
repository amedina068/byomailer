import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ProjectBadge from '../ProjectBadge.vue';

describe('ProjectBadge', () => {
    it('renders the project name', () => {
        const wrapper = mount(ProjectBadge, {
            props: { name: 'Onboarding' },
        });

        expect(wrapper.text()).toBe('Onboarding');
    });

    it('exposes the full name as a title for truncated chips', () => {
        const longName = 'A very long project name that will surely truncate';
        const wrapper = mount(ProjectBadge, {
            props: { name: longName },
        });

        expect(wrapper.attributes('title')).toBe(longName);
        expect(wrapper.text()).toBe(longName);
    });
});
