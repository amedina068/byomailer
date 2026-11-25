import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    router: { patch: vi.fn(), delete: vi.fn(), post: vi.fn() },
}));

import Show from '../Show.vue';

const mountPage = (unsubscribedFromAll: boolean) =>
    mount(Show, {
        props: {
            contactMailUuid: 'mail-uuid',
            contact: { uuid: 'c-1', email: 'ana@example.com', name: 'Ana' },
            topics: [
                {
                    id: 1,
                    name: 'News',
                    description: null,
                    list_name: 'Main',
                    subscribed: true,
                },
            ],
            unsubscribedFromAll,
        },
    });

describe('Preference center', () => {
    it('starts in the unsubscribed state for a contact who unsubscribed from all emails', () => {
        const wrapper = mountPage(true);
        const text = wrapper.text();

        expect(text).toContain('Paused');
        expect(text).not.toContain('Subscribed');
        expect(text).not.toContain('Unsubscribe from all emails');
        expect(text).toContain('You are unsubscribed from all emails.');
        expect(
            wrapper
                .findAll('button')
                .some((b) => b.text() === 'Subscribe again'),
        ).toBe(true);
        expect(
            wrapper
                .findAll('button')
                .find((b) => b.text() === 'Save preferences')
                ?.attributes('disabled'),
        ).toBeDefined();
    });

    it('shows the topics as subscribed for a subscribed contact', () => {
        const text = mountPage(false).text();

        expect(text).toContain('Subscribed');
        expect(text).toContain('Unsubscribe from all emails');
    });
});
