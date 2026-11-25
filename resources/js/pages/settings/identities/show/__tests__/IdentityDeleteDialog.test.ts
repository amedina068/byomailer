import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

const routerDelete = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: (...args: unknown[]) => routerDelete(...args) },
    Link: defineComponent({
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    }),
}));

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import IdentityDeleteDialog, {
    type IdentityUsage,
} from '../IdentityDeleteDialog.vue';

const noUsage: IdentityUsage = { newsletters: [], sequences: [], lists: [] };

const render = (usage: IdentityUsage = noUsage) =>
    mount(IdentityDeleteDialog, {
        props: {
            open: true,
            identityId: 3,
            identityEmail: 'hello@example.com',
            usage,
        },
    });

const removeButton = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAll('button').find((button) => button.text() === 'Remove')!;

describe('Email identity delete dialog', () => {
    beforeEach(() => routerDelete.mockReset());

    it('lets the user remove an email identity that nothing live uses', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Remove email identity?');
        expect(wrapper.text()).toContain('hello@example.com');
        expect(wrapper.text()).toContain('BYOMailer');
        expect(removeButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('lists the live items with links and blocks the remove', () => {
        const wrapper = render({
            newsletters: [{ id: 11, name: 'November news' }],
            sequences: [{ id: 22, name: 'Welcome' }],
            lists: [{ id: 33, name: 'Weekly' }],
        });

        const hrefs = wrapper
            .findAll('a')
            .map((link) => link.attributes('href'));

        expect(wrapper.text()).toContain('November news');
        expect(wrapper.text()).toContain('Welcome');
        expect(wrapper.text()).toContain('Weekly');
        expect(hrefs).toEqual([
            '/newsletters/11',
            '/sequences/22',
            '/lists/33/edit',
        ]);
        expect(removeButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('shows the server message when the server refuses the remove', async () => {
        const wrapper = render();

        await removeButton(wrapper).trigger('click');
        const options = routerDelete.mock.calls[0][1] as {
            onError: (errors: object) => void;
        };
        options.onError({ identity: 'A live sequence uses it.' });
        await nextTick();

        expect(wrapper.text()).toContain('A live sequence uses it.');
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });
});
