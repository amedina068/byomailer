<script setup lang="ts">
import { PhCheckCircle, PhClock, PhWarning } from '@phosphor-icons/vue';
import { computed } from 'vue';

type Variant = 'success' | 'warning' | 'error';

const props = defineProps<{
    status?: string | null;
    label?: string;
    iconSize?: number;
}>();

const normalized = computed(() => {
    const raw = (props.status ?? '').toString().toUpperCase();
    if (raw === 'SUCCESS' || raw === 'VERIFIED') {
        return 'success' satisfies Variant;
    }
    if (raw === 'FAILED' || raw === 'TEMPORARY_FAILURE') {
        return 'error' satisfies Variant;
    }
    return 'warning' satisfies Variant;
});

const defaultLabel = computed(() => {
    if (normalized.value === 'success') return 'Verified';
    if (normalized.value === 'error') return 'Failed';
    return 'Pending';
});

const display = computed(() => props.label ?? defaultLabel.value);

const variantClass = computed(() => {
    if (normalized.value === 'success') {
        return 'bg-[var(--bm-success-light)] text-[var(--bm-success)]';
    }
    if (normalized.value === 'error') {
        return 'bg-[var(--bm-error-light)] text-[var(--bm-error)]';
    }
    return 'bg-[var(--bm-warning-light)] text-[var(--bm-warning)]';
});

const Icon = computed(() => {
    if (normalized.value === 'success') return PhCheckCircle;
    if (normalized.value === 'error') return PhWarning;
    return PhClock;
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-[var(--bm-radius-sm)] px-2 py-0.5 font-mono text-[11px] font-medium"
        :class="variantClass"
    >
        <component :is="Icon" :size="iconSize ?? 12" />
        {{ display }}
    </span>
</template>
