import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import { toast } from '@/composables/useToast';

type Status = 'live' | 'draft';

interface StatusToggleOptions {
    url: () => string;
    isLive: () => boolean;
    /**
     * Unsaved edits that must be saved before the item goes live. The save
     * calls `onSaved` or `onFailed` when it is done.
     */
    unsavedChanges?: {
        isDirty: () => boolean;
        save: (onSaved: () => void, onFailed: () => void) => void;
    };
    onChanged?: (status: Status) => void;
}

/**
 * Opens a confirm dialog, then sends the opposite status. When the server
 * blocks the change, the reason shows as a toast.
 */
export function useStatusToggle(options: StatusToggleOptions) {
    const state = reactive({
        confirming: false,
        processing: false,
        request: (): void => {
            state.confirming = true;
        },
        confirm: (): void => confirm(),
    });

    const finish = (): void => {
        state.processing = false;
        state.confirming = false;
    };

    const send = (status: Status): void => {
        router.patch(
            options.url(),
            { status },
            {
                preserveScroll: true,
                onSuccess: () => options.onChanged?.(status),
                onError: (errors) => {
                    if (errors.status) {
                        toast.error(errors.status);
                    }
                },
                onFinish: finish,
            },
        );
    };

    const confirm = (): void => {
        if (state.processing) {
            return;
        }

        const status: Status = options.isLive() ? 'draft' : 'live';
        const unsaved = options.unsavedChanges;

        state.processing = true;

        if (status === 'live' && unsaved?.isDirty()) {
            unsaved.save(
                () => send(status),
                () => {
                    finish();
                    toast.error('Correct the errors and save, then go live.');
                },
            );

            return;
        }

        send(status);
    };

    return state;
}
