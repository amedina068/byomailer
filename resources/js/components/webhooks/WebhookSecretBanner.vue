<script setup lang="ts">
import { PhCopy } from '@phosphor-icons/vue';
import { nextTick, ref, watch } from 'vue';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    secret: string | null;
    title: string;
}>();

const banner = ref<HTMLElement | null>(null);
const copied = ref(false);

watch(
    () => props.secret,
    (value) => {
        if (value) {
            void nextTick(() => banner.value?.focus());
        }
    },
    { immediate: true },
);

function copySecret(): void {
    if (props.secret) {
        navigator.clipboard.writeText(props.secret);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    }
}
</script>

<template>
    <div v-if="secret" ref="banner" tabindex="-1" class="outline-none">
        <Alert variant="default" class="bg-green-50 dark:bg-green-950">
            <AlertTitle class="text-green-800 dark:text-green-200">{{ title }}</AlertTitle>
            <AlertDescription class="mt-2">
                <p class="mb-2 text-sm text-green-700 dark:text-green-300">
                    <slot name="description" />
                </p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 rounded bg-green-100 p-2 font-mono text-xs break-all text-green-800 dark:bg-green-900 dark:text-green-200">
                        {{ secret }}
                    </code>
                    <Button variant="outline" size="sm" @click="copySecret">
                        <PhCopy :size="16" />
                        {{ copied ? 'Copied!' : 'Copy' }}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>
    </div>
</template>
