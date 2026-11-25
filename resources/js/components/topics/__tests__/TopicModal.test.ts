import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import TopicModal from '../TopicModal.vue';

type VisitOptions = { onStart?: (visit: unknown) => void };

afterEach(() => {
    vi.restoreAllMocks();
});

describe('TopicModal', () => {
    it('disables the create button while the topic saves', async () => {
        let options: VisitOptions = {};
        const visit = vi.spyOn(router, 'visit').mockImplementation(((
            _url: string,
            visitOptions: VisitOptions,
        ) => {
            options = visitOptions;
        }) as never);

        const wrapper = mount(TopicModal, {
            props: { lists: [{ id: 3, name: 'News' }], isOpen: true },
        });

        await wrapper.get('input[name="name"]').setValue('Product updates');
        await wrapper.get('form').trigger('submit');
        options.onStart?.({});
        await nextTick();

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/lists/3/topics');
        expect(
            wrapper.get('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();
    });
});
