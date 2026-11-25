import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

const postMock = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args: unknown[]) => postMock(...args) },
}));

const toastError = vi.fn();
vi.mock('@/composables/useToast', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@/components/projects/ProjectMultiSelect.vue', () => ({
    default: defineComponent({
        name: 'ProjectMultiSelectStub',
        props: {
            modelValue: { type: Array, default: () => [] },
            options: { type: Array, default: () => [] },
        },
        emits: ['update:modelValue'],
        template:
            '<div><slot name="trigger" :count="modelValue.length" :label="String(modelValue.length)" /></div>',
    }),
}));

import ProjectQuickAssign from '../ProjectQuickAssign.vue';

interface PostOptions {
    preserveScroll?: boolean;
    preserveState?: boolean;
    only?: string[];
    onError?: () => void;
    onFinish?: () => void;
}

const options = [
    { id: 1, name: 'Onboarding' },
    { id: 2, name: 'Product Launch' },
];

function mountQuickAssign(props: {
    projectIds?: number[];
    reloadProps?: string[];
}) {
    return mount(ProjectQuickAssign, {
        props: {
            projectIds: props.projectIds ?? [],
            options,
            syncUrl: '/sync-url',
            reloadProps: props.reloadProps,
        },
    });
}

function emitSelection(wrapper: ReturnType<typeof mountQuickAssign>, ids: number[]) {
    wrapper.getComponent({ name: 'ProjectMultiSelectStub' }).vm.$emit(
        'update:modelValue',
        ids,
    );
}

function lastPostOptions(): PostOptions {
    return postMock.mock.calls[postMock.mock.calls.length - 1][2] as PostOptions;
}

describe('ProjectQuickAssign', () => {
    beforeEach(() => {
        postMock.mockClear();
        toastError.mockClear();
    });

    it('posts a single sync when toggled once', () => {
        const wrapper = mountQuickAssign({ projectIds: [] });

        emitSelection(wrapper, [1]);

        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toBe('/sync-url');
        expect(postMock.mock.calls[0][1]).toEqual({ project_ids: [1] });
    });

    it('serializes rapid toggles and posts only the latest after the first settles', () => {
        const wrapper = mountQuickAssign({ projectIds: [] });

        emitSelection(wrapper, [1]);
        emitSelection(wrapper, [1, 2]);

        expect(postMock).toHaveBeenCalledTimes(1);

        lastPostOptions().onFinish?.();

        expect(postMock).toHaveBeenCalledTimes(2);
        expect(postMock.mock.calls[1][1]).toEqual({ project_ids: [1, 2] });
    });

    it('does not clobber the optimistic selection when props update mid-flight', async () => {
        const wrapper = mountQuickAssign({ projectIds: [] });

        emitSelection(wrapper, [1]);
        await wrapper.setProps({ projectIds: [5] });
        await nextTick();

        expect(wrapper.get('button').attributes('aria-label')).toContain(
            '1 project',
        );
    });

    it('reverts and toasts on error', async () => {
        const wrapper = mountQuickAssign({ projectIds: [] });

        emitSelection(wrapper, [1]);
        lastPostOptions().onError?.();
        await nextTick();

        expect(toastError).toHaveBeenCalledTimes(1);
        expect(wrapper.get('button').attributes('aria-label')).toBe(
            'Assign to projects',
        );
    });

    it('scopes the reload with only + preserveState when reloadProps is set', () => {
        const wrapper = mountQuickAssign({
            projectIds: [],
            reloadProps: ['newsletters'],
        });

        emitSelection(wrapper, [1]);

        const opts = lastPostOptions();
        expect(opts.only).toEqual(['newsletters']);
        expect(opts.preserveState).toBe(true);
    });

    it('omits only when reloadProps is not provided', () => {
        const wrapper = mountQuickAssign({ projectIds: [] });

        emitSelection(wrapper, [1]);

        expect(lastPostOptions().only).toBeUndefined();
    });
});
