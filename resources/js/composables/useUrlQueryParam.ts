import { onMounted, ref, watch, type Ref } from 'vue';

type Validator<T> = (raw: string) => T | null;

interface Options<T extends string> {
    validate?: Validator<T>;
    debounceMs?: number;
}

export function useUrlQueryParam<T extends string>(
    key: string,
    defaultValue: T,
    options: Options<T> = {},
): Ref<T> {
    const { validate, debounceMs = 0 } = options;

    const readInitial = (): T => {
        if (typeof window === 'undefined') {
            return defaultValue;
        }
        const raw = new URL(window.location.href).searchParams.get(key);
        if (raw === null) {
            return defaultValue;
        }
        if (validate) {
            return validate(raw) ?? defaultValue;
        }
        return raw as T;
    };

    const state = ref(readInitial()) as Ref<T>;
    let timeout: ReturnType<typeof setTimeout> | null = null;

    const writeUrl = (): void => {
        if (typeof window === 'undefined') {
            return;
        }
        const url = new URL(window.location.href);
        const value = state.value;
        if (value === defaultValue || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
        window.history.replaceState(window.history.state, '', url.toString());
    };

    watch(state, () => {
        if (timeout) {
            clearTimeout(timeout);
        }
        if (debounceMs > 0) {
            timeout = setTimeout(writeUrl, debounceMs);
        } else {
            writeUrl();
        }
    });

    onMounted(writeUrl);

    return state;
}
