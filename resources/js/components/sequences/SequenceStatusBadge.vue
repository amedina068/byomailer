<script setup lang="ts">
import { computed } from 'vue';

import { sequenceStatusLabel, type SequenceStatusOption } from '@/types/sequences';

const props = defineProps<{
    status: string;
    statuses: SequenceStatusOption[];
}>();

const colors = computed(() =>
    props.status === 'live'
        ? { fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' }
        : { fg: 'var(--bm-warning)', bg: 'var(--bm-warning-dim)' },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 px-2 py-0.5"
        :style="{
            backgroundColor: colors.bg,
            color: colors.fg,
            fontFamily: 'var(--bm-font-mono)',
            fontSize: '10px',
            fontWeight: 500,
            borderRadius: '3px',
        }"
    >
        <span class="size-1.5 rounded-full" :style="{ backgroundColor: colors.fg }"></span>
        {{ sequenceStatusLabel(props.statuses, props.status) }}
    </span>
</template>
