import { Form, router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * UNMOCKED characterization tests for the REAL `@inertiajs/vue3` <Form> component.
 *
 * <Form> IS exported by @inertiajs/vue3 v2.3.11 (verified in the package's
 * types/index.d.ts). It owns an internal useForm and exposes `processing`,
 * `errors`, etc. as slot props. We mount it for real, spy `router.visit` to stub
 * transport, capture the visit lifecycle callbacks, and drive them by hand to
 * assert how the `processing` slot prop moves across states in v2.
 */

type CapturedVisit = {
    options: {
        method: string;
        data: Record<string, unknown>;
        onStart: (visit: unknown) => void;
        onSuccess: (page: unknown) => void | Promise<void>;
        onError: (errors: Record<string, string>) => void;
        onFinish: (visit: unknown) => void;
        onBefore: (visit: unknown) => void;
    };
};

let captured: CapturedVisit | undefined;
let visitSpy: ReturnType<typeof vi.spyOn>;

afterEach(() => {
    visitSpy?.mockRestore();
    captured = undefined;
});

function mountForm() {
    captured = undefined;
    visitSpy = vi.spyOn(router, 'visit').mockImplementation(((_url: string, options: CapturedVisit['options']) => {
        captured = { options };
        return undefined;
    }) as never);

    return mount(Form, {
        props: { action: '/subscribe', method: 'post' },
        slots: {
            default: `
                <template #default="{ processing, errors }">
                    <span class="state">{{ processing ? 'processing' : 'idle' }}</span>
                    <span class="err">{{ errors.email || '' }}</span>
                    <input name="email" value="a@b.com" />
                    <button type="submit">Save</button>
                </template>
            `,
        },
    });
}

describe('Form component processing slot prop baseline (real @inertiajs/vue3)', () => {
    it('exposes processing=false (idle) before submit', () => {
        const wrapper = mountForm();

        expect(wrapper.find('.state').text()).toBe('idle');
    });

    it('renders a <form> with the resolved action and method', () => {
        const wrapper = mountForm();
        const form = wrapper.find('form');

        expect(form.attributes('action')).toBe('/subscribe');
        expect(form.attributes('method')).toBe('post');
    });

    it('submitting routes through router.visit and flips processing across states', async () => {
        const wrapper = mountForm();

        await wrapper.find('form').trigger('submit');

        expect(visitSpy).toHaveBeenCalledTimes(1);
        expect(captured?.options.method).toBe('post');
        expect(captured?.options.data).toEqual({ email: 'a@b.com' });

        captured!.options.onStart({});
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.state').text()).toBe('processing');

        // v3 behavior (changed from v2): processing resets only in onFinish, so it
        // stays 'processing' through onSuccess and flips to 'idle' only after onFinish.
        await captured!.options.onSuccess({ props: {} });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.state').text()).toBe('processing');

        captured!.options.onFinish({});
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.state').text()).toBe('idle');
    });

    it('surfaces validation errors via the errors slot prop, processing clears in onFinish', async () => {
        const wrapper = mountForm();

        await wrapper.find('form').trigger('submit');
        captured!.options.onStart({});
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.state').text()).toBe('processing');

        captured!.options.onError({ email: 'The email field is required.' });
        await wrapper.vm.$nextTick();

        // v3 behavior: errors surface immediately; processing stays until onFinish.
        expect(wrapper.find('.err').text()).toBe('The email field is required.');
        expect(wrapper.find('.state').text()).toBe('processing');

        captured!.options.onFinish({});
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.state').text()).toBe('idle');
    });
});
