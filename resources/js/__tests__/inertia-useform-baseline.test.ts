import { router, useForm } from '@inertiajs/vue3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

/**
 * UNMOCKED characterization tests for the REAL `@inertiajs/vue3` useForm helper.
 *
 * These exercise the library's actual reactive form logic. Only the network
 * transport is stubbed: `router.visit` is spied so no HTTP happens, and the
 * lifecycle callbacks (onStart/onSuccess/onError/onFinish) the library wires
 * onto the visit are captured and driven by hand. That lets us assert the exact
 * v2 timing of `processing`, `errors`, `wasSuccessful`, etc. so a regression is
 * visible after upgrading to @inertiajs/vue3 v3.
 *
 * Every router method (post/put/patch/delete/get) delegates to `router.visit`,
 * so spying on `visit` alone captures all submit paths.
 */

type CapturedVisit = {
    url: string;
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

beforeEach(() => {
    captured = undefined;
    visitSpy = vi.spyOn(router, 'visit').mockImplementation(((url: string, options: CapturedVisit['options']) => {
        captured = { url, options };
        return undefined;
    }) as never);
});

afterEach(() => {
    visitSpy.mockRestore();
});

describe('useForm reactive baseline (real @inertiajs/vue3)', () => {
    it('exposes the initial data keys and default reactive flags', () => {
        const form = useForm({ email: 'a@b.com', name: 'Amy' });

        expect(form.email).toBe('a@b.com');
        expect(form.name).toBe('Amy');
        expect(form.processing).toBe(false);
        expect(form.errors).toEqual({});
        expect(form.hasErrors).toBe(false);
        expect(form.wasSuccessful).toBe(false);
        expect(form.recentlySuccessful).toBe(false);
        expect(form.isDirty).toBe(false);
    });

    it('routes post() through router.visit with method + transformed data', () => {
        const form = useForm({ email: 'a@b.com' });

        form.post('/subscribe');

        expect(visitSpy).toHaveBeenCalledTimes(1);
        expect(captured?.url).toBe('/subscribe');
        expect(captured?.options.method).toBe('post');
        expect(captured?.options.data).toEqual({ email: 'a@b.com' });
    });

    it('transitions processing false -> true -> false across a successful submit', async () => {
        const form = useForm({ email: 'a@b.com' });

        expect(form.processing).toBe(false);

        form.post('/subscribe');
        // Transport is stubbed, so the library has NOT flipped processing yet.
        expect(form.processing).toBe(false);

        captured!.options.onStart({});
        expect(form.processing).toBe(true);

        // v3 behavior (changed from v2): onSuccess NO LONGER resets processing;
        // processing stays true through onSuccess and is reset only inside onFinish.
        // (In v2 processing flipped to false here, before onFinish.)
        await captured!.options.onSuccess({ props: {} });
        expect(form.processing).toBe(true);

        captured!.options.onFinish({});
        expect(form.processing).toBe(false);
    });

    it('sets wasSuccessful/recentlySuccessful only after onSuccess (v2 baseline)', async () => {
        const form = useForm({ email: 'a@b.com' });

        form.post('/subscribe');
        captured!.options.onBefore({});
        captured!.options.onStart({});
        expect(form.wasSuccessful).toBe(false);
        expect(form.recentlySuccessful).toBe(false);

        await captured!.options.onSuccess({ props: {} });
        expect(form.wasSuccessful).toBe(true);
        expect(form.recentlySuccessful).toBe(true);
    });

    it('populates errors on a 422-style response and resets processing only in onFinish', () => {
        const form = useForm({ email: '' });

        form.post('/subscribe');
        captured!.options.onStart({});
        expect(form.processing).toBe(true);

        // Inertia surfaces a 422 validation response to the form as onError(errorBag).
        captured!.options.onError({ email: 'The email field is required.' });

        // v3 behavior (changed from v2): onError NO LONGER resets processing;
        // errors populate immediately but processing stays true until onFinish.
        expect(form.hasErrors).toBe(true);
        expect(form.errors.email).toBe('The email field is required.');
        expect(form.processing).toBe(true);

        captured!.options.onFinish({});
        expect(form.processing).toBe(false);
    });

    it('clears prior errors when a later submit succeeds', async () => {
        const form = useForm({ email: '' });

        form.post('/subscribe');
        captured!.options.onError({ email: 'Required' });
        expect(form.hasErrors).toBe(true);

        form.post('/subscribe');
        await captured!.options.onSuccess({ props: {} });

        expect(form.hasErrors).toBe(false);
        expect(form.errors).toEqual({});
    });

    it('setError / clearErrors mutate the reactive errors bag directly', () => {
        const form = useForm({ email: '', name: '' });

        form.setError('email', 'Bad');
        expect(form.errors.email).toBe('Bad');
        expect(form.hasErrors).toBe(true);

        form.setError({ email: 'Bad2', name: 'Missing' });
        expect(form.errors.email).toBe('Bad2');
        expect(form.errors.name).toBe('Missing');

        form.clearErrors('email');
        expect(form.errors.email).toBeUndefined();
        expect(form.errors.name).toBe('Missing');

        form.clearErrors();
        expect(form.hasErrors).toBe(false);
    });

    it('tracks isDirty and reset() restores the original defaults', async () => {
        const form = useForm({ email: 'a@b.com' });

        expect(form.isDirty).toBe(false);
        form.email = 'changed@b.com';
        // isDirty is derived by a deep reactive watcher, so it settles on the next tick.
        await nextTick();
        expect(form.isDirty).toBe(true);

        form.reset();
        await nextTick();
        expect(form.email).toBe('a@b.com');
        expect(form.isDirty).toBe(false);
    });

    it('delete() delegates to router.visit with the delete method', () => {
        const form = useForm({ email: 'a@b.com' });

        form.delete('/subscribe/1');

        expect(captured?.options.method).toBe('delete');
        expect(captured?.url).toBe('/subscribe/1');
    });
});
