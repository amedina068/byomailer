import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Form: defineComponent({
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    }),
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import ListEdit from '../Edit.vue';

const mountListEdit = (optInEnabled: boolean) =>
    mount(ListEdit, {
        props: {
            list: {
                id: 1,
                uuid: 'list-uuid',
                name: 'Weekly',
                opt_in_enabled: optInEnabled,
                message: null,
            },
            emailIdentities: [],
        },
        global: {
            stubs: {
                TemplatePickerModal: true,
                TiptapEditor: true,
            },
        },
    });

describe('List edit page', () => {
    it('says that each new member of an opt-in list must confirm', () => {
        const wrapper = mount(ListEdit, {
            props: {
                list: {
                    id: 1,
                    uuid: 'list-uuid',
                    name: 'Weekly',
                    opt_in_enabled: false,
                    message: null,
                },
                emailIdentities: [],
            },
            global: {
                stubs: {
                    TemplatePickerModal: true,
                    TiptapEditor: true,
                },
            },
        });

        expect(wrapper.text()).toContain(
            'Each contact that joins this list gets a confirmation email.',
        );
        expect(wrapper.text()).not.toContain('added via API');
    });

    it('shows the opt-in fields when the user turns on double opt-in', async () => {
        const wrapper = mountListEdit(false);
        const toggle = wrapper.get('#opt_in_enabled');

        expect(toggle.attributes('aria-checked')).toBe('false');
        expect(wrapper.find('#opt_in_subject').exists()).toBe(false);

        await toggle.trigger('click');

        expect(toggle.attributes('aria-checked')).toBe('true');
        expect(wrapper.find('#opt_in_subject').exists()).toBe(true);
        expect(
            wrapper.get('input[name="opt_in_enabled"]').attributes('value'),
        ).toBe('1');
    });

    it('shows the switch as on for a list that already has opt-in', () => {
        const wrapper = mountListEdit(true);

        expect(wrapper.get('#opt_in_enabled').attributes('aria-checked')).toBe(
            'true',
        );
        expect(wrapper.find('#opt_in_subject').exists()).toBe(true);
    });

    it('links to the email identity setup when the account has none', () => {
        const wrapper = mountListEdit(true);

        expect(wrapper.text()).toContain('No email identities yet.');
        expect(wrapper.text()).not.toContain('sending identity');
    });
});
