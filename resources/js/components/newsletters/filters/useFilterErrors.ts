import { computed, watch, type Ref } from 'vue';

export type FilterErrors = Partial<Record<string, string>>;

const isRowKey = (key: string): boolean => /^filters\.\d+(\.|$)/.test(key);

/**
 * Reads the `filters` and `filters.N.*` validation errors of a save.
 * The editor opens when a filter row has an error. After a save, the
 * editor stays open if the server rejected the filters.
 */
export const useFilterErrors = (options: {
    errors: () => FilterErrors;
    legacyError: () => string | undefined;
    processing: () => boolean;
    isEditing: Ref<boolean>;
    openEditor: () => void;
}) => {
    const messages = computed(() =>
        Object.entries(options.errors()).filter(
            (entry): entry is [string, string] => Boolean(entry[1]),
        ),
    );

    const topLevelError = computed(
        () => options.legacyError() ?? options.errors().filters,
    );
    const hasRowErrors = computed(() =>
        messages.value.some(([key]) => isRowKey(key)),
    );
    const hasFilterErrors = computed(
        () => hasRowErrors.value || Boolean(topLevelError.value),
    );

    /** The messages of one filter row, joined in one line. */
    const errorFor = (index: number): string | undefined =>
        [
            ...new Set(
                messages.value
                    .filter(
                        ([key]) =>
                            key === `filters.${index}` ||
                            key.startsWith(`filters.${index}.`),
                    )
                    .map(([, message]) => message),
            ),
        ].join(' ') || undefined;

    watch(
        hasRowErrors,
        (hasErrors) => {
            if (hasErrors && !options.isEditing.value) {
                options.openEditor();
            }
        },
        { immediate: true },
    );

    watch(options.processing, (isProcessing, wasProcessing) => {
        if (wasProcessing && !isProcessing && !hasFilterErrors.value) {
            options.isEditing.value = false;
        }
    });

    return { topLevelError, errorFor };
};
