import { ref } from 'vue';

type ToastVariant = 'success' | 'error';

interface Toast {
    id: number;
    message: string;
    variant: ToastVariant;
}

let nextId = 0;

const toasts = ref<Toast[]>([]);

function push(message: string, variant: ToastVariant, duration: number): void {
    const id = nextId++;

    toasts.value.push({ id, message, variant });

    setTimeout(() => dismiss(id), duration);
}

function success(message: string, duration = 4000): void {
    push(message, 'success', duration);
}

function error(message: string, duration = 6000): void {
    push(message, 'error', duration);
}

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

export function useToast() {
    return { toasts, success, error, dismiss };
}

export const toast = { success, error };
