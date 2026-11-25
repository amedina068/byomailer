import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { post, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { post } }));
vi.mock('@/composables/useToast', () => ({
    toast: { error: toastError, success: vi.fn() },
}));
vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: defineComponent({
            props: { open: Boolean },
            template: '<div v-if="open" data-test="dialog"><slot /></div>',
        }),
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import AbTestResults from '../AbTestResults.vue';

const variantMetrics = (total: number) => ({
    total,
    opened_count: 0,
    clicked_count: 0,
    open_rate: null,
    click_rate: null,
});

const mountResults = () =>
    mount(AbTestResults, {
        props: {
            newsletterId: 7,
            status: 'ab_testing',
            winnerVariant: null,
            metrics: { a: variantMetrics(120), b: variantMetrics(118) },
        },
    });

type Wrapper = ReturnType<typeof mountResults>;

const pickButton = (wrapper: Wrapper, index: number) =>
    wrapper
        .findAll('button')
        .filter((button) => button.text() === 'Pick winner')[index];

const confirmButton = (wrapper: Wrapper) =>
    wrapper
        .find('[data-test="dialog"]')
        .findAll('button')
        .find((button) => button.text().startsWith('Pick variant'));

describe('A/B test results', () => {
    beforeEach(() => {
        post.mockReset();
        toastError.mockReset();
    });

    it('asks for a confirmation before it picks a winner', async () => {
        const wrapper = mountResults();

        await pickButton(wrapper, 1).trigger('click');

        expect(post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="dialog"]').text()).toContain(
            'Pick variant B as the winner?',
        );
    });

    it('picks the winner when the user confirms', async () => {
        const wrapper = mountResults();

        await pickButton(wrapper, 1).trigger('click');
        await confirmButton(wrapper)?.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({ winner_variant: 'b' });
    });

    it('shows the server error in a toast', async () => {
        post.mockImplementation((_url, _data, options) => {
            options.onError({
                status: 'Another request picked the A/B winner first.',
            });
        });
        const wrapper = mountResults();

        await pickButton(wrapper, 0).trigger('click');
        await confirmButton(wrapper)?.trigger('click');

        expect(toastError).toHaveBeenCalledWith(
            'Another request picked the A/B winner first.',
        );
    });
});
