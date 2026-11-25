import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogClose: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

vi.mock('@/components/ComplianceWarnings.vue', () => ({
    default: defineComponent({ template: '<div />' }),
}));

import SendConfirmDialog from '../SendConfirmDialog.vue';

const render = (props: Record<string, unknown>) =>
    mount(SendConfirmDialog, {
        props: {
            open: true,
            action: 'send_now',
            recipientCount: 1234,
            identityLabel: 'Ana <ana@example.com>',
            scheduledFor: '',
            timezoneLabel: 'America/Mexico_City',
            processing: false,
            withoutUnsubscribeLink: [],
            ...props,
        },
    });

describe('Send confirmation dialog', () => {
    it('shows the number of contacts and the email identity', () => {
        const wrapper = render({});

        expect(wrapper.text()).toContain('Send now to 1,234 contacts');
        expect(wrapper.text()).toContain('from Ana <ana@example.com>');
        expect(wrapper.find('.font-mono').text()).toBe('1,234');
    });

    it('uses the singular for one contact', () => {
        expect(render({ recipientCount: 1 }).text()).toContain(
            'Send now to 1 contact from',
        );
    });

    it('shows the date, the time and the time zone of a scheduled send', () => {
        const text = render({
            action: 'schedule',
            scheduledFor: '2026-12-01T09:30',
        }).text();

        expect(text).toContain('Schedule this newsletter?');
        expect(text).toContain('2026-12-01 09:30 (America/Mexico_City)');
    });

    it('emits confirm when the user confirms', async () => {
        const wrapper = render({ action: 'schedule' });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Schedule send')
            ?.trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });
});
