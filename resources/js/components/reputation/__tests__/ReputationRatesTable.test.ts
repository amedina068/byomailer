import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Deferred: defineComponent({ template: '<div><slot /></div>' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
}));

import ReputationRatesTable from '../ReputationRatesTable.vue';

const thresholds = {
    bounce_review: 5,
    bounce_pause: 10,
    complaint_review: 0.1,
    complaint_pause: 0.5,
};

describe('ReputationRatesTable', () => {
    it('names the risk level in text, not only in color', () => {
        const wrapper = mount(ReputationRatesTable, {
            props: {
                thresholds,
                rates: {
                    warn: false,
                    windows: [
                        {
                            key: '7d',
                            sent: 1000,
                            bounce_rate: 6,
                            complaint_rate: 0.6,
                            bounce_level: 'review',
                            complaint_level: 'pause',
                        },
                        {
                            key: '30d',
                            sent: 1000,
                            bounce_rate: 1,
                            complaint_rate: 0,
                            bounce_level: 'ok',
                            complaint_level: 'ok',
                        },
                    ],
                },
            },
        });

        const rows = wrapper.findAll('tbody tr');
        expect(rows[0].text()).toContain('6% Review level');
        expect(rows[0].text()).toContain('0.6% Pause level');
        expect(rows[1].text()).not.toContain('level');
    });
});
