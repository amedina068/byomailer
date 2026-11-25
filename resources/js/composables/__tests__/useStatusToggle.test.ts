import { beforeEach, describe, expect, it, vi } from 'vitest';

type VisitOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const { patch, toastError } = vi.hoisted(() => ({
    patch: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { patch } }));
vi.mock('@/composables/useToast', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

import { useStatusToggle } from '../useStatusToggle';

const lastVisit = (): VisitOptions => patch.mock.calls.at(-1)?.[2] ?? {};

describe('useStatusToggle', () => {
    beforeEach(() => {
        patch.mockReset();
        toastError.mockReset();
    });

    it('sends the opposite status and closes the dialog when it finishes', () => {
        const toggle = useStatusToggle({
            url: () => '/sequences/1/status',
            isLive: () => false,
        });

        toggle.request();
        expect(toggle.confirming).toBe(true);

        toggle.confirm();

        expect(patch).toHaveBeenCalledWith(
            '/sequences/1/status',
            { status: 'live' },
            expect.objectContaining({ preserveScroll: true }),
        );

        lastVisit().onFinish?.();

        expect(toggle.confirming).toBe(false);
        expect(toggle.processing).toBe(false);
    });

    it('shows the reason when the server blocks the change', () => {
        const toggle = useStatusToggle({
            url: () => '/sequences/1/status',
            isLive: () => false,
        });

        toggle.request();
        toggle.confirm();
        lastVisit().onError?.({ status: 'Verify an email identity first.' });

        expect(toastError).toHaveBeenCalledWith(
            'Verify an email identity first.',
        );
    });

    it('saves unsaved changes before it goes live', () => {
        const save = vi.fn();
        const toggle = useStatusToggle({
            url: () => '/visual-automations/1/status',
            isLive: () => false,
            unsavedChanges: { isDirty: () => true, save },
        });

        toggle.confirm();

        expect(save).toHaveBeenCalledTimes(1);
        expect(patch).not.toHaveBeenCalled();

        const [onSaved] = save.mock.calls[0];
        onSaved();

        expect(patch).toHaveBeenCalledWith(
            '/visual-automations/1/status',
            { status: 'live' },
            expect.anything(),
        );
    });

    it('does not go live when the save fails', () => {
        const save = vi.fn();
        const toggle = useStatusToggle({
            url: () => '/visual-automations/1/status',
            isLive: () => false,
            unsavedChanges: { isDirty: () => true, save },
        });

        toggle.request();
        toggle.confirm();

        const [, onFailed] = save.mock.calls[0];
        onFailed();

        expect(patch).not.toHaveBeenCalled();
        expect(toggle.confirming).toBe(false);
        expect(toggle.processing).toBe(false);
        expect(toastError).toHaveBeenCalled();
    });

    it('does not save when there are no unsaved changes or when it pauses', () => {
        const save = vi.fn();

        useStatusToggle({
            url: () => '/visual-automations/1/status',
            isLive: () => false,
            unsavedChanges: { isDirty: () => false, save },
        }).confirm();

        useStatusToggle({
            url: () => '/visual-automations/1/status',
            isLive: () => true,
            unsavedChanges: { isDirty: () => true, save },
        }).confirm();

        expect(save).not.toHaveBeenCalled();
        expect(patch).toHaveBeenCalledTimes(2);
        expect(patch.mock.calls[1][1]).toEqual({ status: 'draft' });
    });

    it('calls onChanged with the new status after a success', () => {
        const onChanged = vi.fn();
        const toggle = useStatusToggle({
            url: () => '/visual-automations/1/status',
            isLive: () => true,
            onChanged,
        });

        toggle.confirm();
        lastVisit().onSuccess?.();

        expect(onChanged).toHaveBeenCalledWith('draft');
    });
});
