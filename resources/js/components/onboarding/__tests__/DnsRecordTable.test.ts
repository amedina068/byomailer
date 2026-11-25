import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import DnsRecordTable from '../DnsRecordTable.vue';

const records = [
    { type: 'TXT', name: '_amazonses.example.com', value: 'token-value' },
    {
        type: 'MX',
        name: 'mas.example.com',
        value: 'feedback-smtp',
        priority: 10,
    },
];

describe('DNS record table', () => {
    it('scrolls sideways on a narrow screen instead of cutting off columns', () => {
        const wrapper = mount(DnsRecordTable, { props: { records } });

        expect(
            wrapper.find('table').element.parentElement?.className,
        ).toContain('overflow-x-auto');
    });

    it('names each copy button by the record type and the field', () => {
        const wrapper = mount(DnsRecordTable, { props: { records } });

        const labels = wrapper
            .findAll('button')
            .map((button) => button.attributes('aria-label'));

        expect(labels).toEqual([
            'Copy TXT name',
            'Copy TXT value',
            'Copy MX name',
            'Copy MX value',
        ]);
    });

    it('announces the copy to screen readers', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
        });
        const wrapper = mount(DnsRecordTable, { props: { records } });
        const live = () => wrapper.get('[aria-live="polite"]');

        expect(live().text()).toBe('');

        await wrapper.findAll('button')[1].trigger('click');
        await flushPromises();

        expect(live().text()).toBe('Copied TXT value');
    });
});
