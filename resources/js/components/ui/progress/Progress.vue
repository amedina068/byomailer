<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        value?: number;
        max?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        value: 0,
        max: 100,
    },
);

const percentage = computed(() => {
    if (props.max <= 0) {
        return 0;
    }
    return Math.min(100, Math.max(0, (props.value / props.max) * 100));
});
</script>

<template>
    <div
        role="progressbar"
        :aria-valuenow="value"
        :aria-valuemin="0"
        :aria-valuemax="max"
        :class="
            cn(
                'relative h-2 w-full overflow-hidden rounded-full bg-primary/20',
                props.class,
            )
        "
    >
        <div
            class="h-full bg-primary transition-all duration-300"
            :style="{ width: `${percentage}%` }"
        />
    </div>
</template>
