import { ref, watch } from 'vue';

const STORAGE_KEY = 'docs-code-language';

const activeLanguage = ref<string>(
    (typeof window !== 'undefined' && localStorage.getItem(STORAGE_KEY)) || 'bash',
);

watch(activeLanguage, (value) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, value);
    }
});

export function useCodeLanguage() {
    return activeLanguage;
}
