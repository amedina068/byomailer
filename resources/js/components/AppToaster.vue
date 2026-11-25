<script setup lang="ts">
import { useToast } from '@/composables/useToast';

const { toasts, dismiss } = useToast();

const variantClasses = {
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
} as const;
</script>

<template>
    <Teleport to="body">
        <div
            role="status"
            aria-live="polite"
            class="fixed top-4 right-4 z-[9999] flex flex-col gap-2"
        >
            <TransitionGroup
                enter-from-class="translate-x-full opacity-0"
                enter-active-class="transition duration-300 ease-out"
                enter-to-class="translate-x-0 opacity-100"
                leave-from-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-to-class="translate-x-full opacity-0"
            >
                <button
                    v-for="t in toasts"
                    :key="t.id"
                    type="button"
                    class="flex max-w-sm min-w-64 cursor-pointer items-center gap-2 rounded-lg border px-4 py-3 text-sm shadow-lg"
                    :class="variantClasses[t.variant]"
                    @click="dismiss(t.id)"
                >
                    <svg
                        v-if="t.variant === 'success'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        class="size-5 shrink-0"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <svg
                        v-else
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        class="size-5 shrink-0"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 0-2 0v4a1 1 0 1 0 2 0V6Zm-1 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <span>{{ t.message }}</span>
                </button>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
