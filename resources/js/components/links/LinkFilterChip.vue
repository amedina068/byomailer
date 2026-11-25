<script setup lang="ts">
import { PhCursorClick, PhX } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { linkKeyLabel } from '@/lib/linkClicks';

const props = defineProps<{
    linkKey: string;
}>();

const emit = defineEmits<{
    clear: [];
}>();

const label = computed(() => linkKeyLabel(props.linkKey));
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1.5 rounded-(--bm-radius-sm) border border-(--bm-accent-border) bg-(--bm-accent-bright-dim) py-0.5 pr-0.5 pl-2 text-xs text-(--bm-accent)"
        data-testid="link-filter-chip"
    >
        <PhCursorClick :size="12" class="shrink-0" />
        <span class="shrink-0 opacity-70">Clicked</span>
        <span
            class="truncate font-(family-name:--bm-font-mono) font-medium"
            :title="label"
        >
            {{ label }}
        </span>
        <button
            type="button"
            class="inline-flex size-5 shrink-0 items-center justify-center rounded-(--bm-radius-sm) transition-colors hover:bg-(--bm-accent-dim) focus-visible:ring-2 focus-visible:ring-(--bm-accent) focus-visible:outline-none"
            aria-label="Clear link filter"
            @click="emit('clear')"
        >
            <PhX :size="12" />
        </button>
    </span>
</template>
