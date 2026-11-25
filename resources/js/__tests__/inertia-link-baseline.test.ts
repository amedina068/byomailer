import { Link, router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/**
 * UNMOCKED characterization tests for the REAL `@inertiajs/vue3` <Link>.
 *
 * The component is mounted for real; only `router.visit` is spied so no HTTP
 * happens. We assert the visit is invoked with the method and defaults the v2
 * <Link> derives from its props, so a behavioral change in v3 is visible.
 */

let visitSpy: ReturnType<typeof vi.spyOn>;

afterEach(() => {
    visitSpy?.mockRestore();
});

function mountLink(props: Record<string, unknown>) {
    visitSpy = vi.spyOn(router, 'visit').mockImplementation((() => undefined) as never);
    const Wrapper = defineComponent({
        setup() {
            return () => h(Link, props, () => 'Go');
        },
    });
    return mount(Wrapper);
}

describe('Link click -> router.visit baseline (real @inertiajs/vue3)', () => {
    it('renders an <a> for a default GET link', () => {
        const wrapper = mountLink({ href: '/dashboard' });

        expect(wrapper.find('a').exists()).toBe(true);
        expect(wrapper.find('a').attributes('href')).toBe('/dashboard');
    });

    it('visits with method=get and preserveState=false for a GET link', async () => {
        const wrapper = mountLink({ href: '/dashboard' });

        await wrapper.find('a').trigger('click');

        expect(visitSpy).toHaveBeenCalledTimes(1);
        const [href, options] = visitSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(href).toBe('/dashboard');
        expect(options.method).toBe('get');
        // v2 baseline: preserveState defaults to (method !== 'get') === false for GET.
        expect(options.preserveState).toBe(false);
    });

    it('renders a <button> and visits with method=delete for a DELETE link', async () => {
        // v2 baseline: a non-GET <Link> whose `as` is the default "a" renders a <button>.
        // v2 baseline — v3 changes: verify the element tag + method default still hold after upgrade.
        const wrapper = mountLink({ href: '/contacts/5', method: 'delete' });

        expect(wrapper.find('button').exists()).toBe(true);

        await wrapper.find('button').trigger('click');

        expect(visitSpy).toHaveBeenCalledTimes(1);
        const [href, options] = visitSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(href).toBe('/contacts/5');
        expect(options.method).toBe('delete');
        // Non-GET methods default preserveState to true.
        expect(options.preserveState).toBe(true);
    });

    it('forwards data + method for a POST link', async () => {
        const wrapper = mountLink({ href: '/logout', method: 'post', data: { token: 'x' } });

        await wrapper.find('button').trigger('click');

        const [href, options] = visitSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(href).toBe('/logout');
        expect(options.method).toBe('post');
        expect(options.data).toEqual({ token: 'x' });
    });
});
