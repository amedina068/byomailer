import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

export interface ToolbarMenuApi {
    isOpen: Ref<boolean>;
    rootRef: Ref<HTMLElement | null>;
    open: () => void;
    close: () => void;
    toggle: () => void;
}

export const useToolbarMenu = (): ToolbarMenuApi => {
    const isOpen = ref(false);
    const rootRef = ref<HTMLElement | null>(null);

    const close = (): void => {
        isOpen.value = false;
    };

    const open = (): void => {
        isOpen.value = true;
    };

    const toggle = (): void => {
        isOpen.value = !isOpen.value;
    };

    const handleOutsideClick = (event: MouseEvent): void => {
        if (!isOpen.value || !rootRef.value) {
            return;
        }

        if (!rootRef.value.contains(event.target as Node)) {
            close();
        }
    };

    const handleKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            close();
        }
    };

    onMounted(() => {
        document.addEventListener('mousedown', handleOutsideClick);
        document.addEventListener('keydown', handleKeyDown);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('mousedown', handleOutsideClick);
        document.removeEventListener('keydown', handleKeyDown);
    });

    return { isOpen, rootRef, open, close, toggle };
};
