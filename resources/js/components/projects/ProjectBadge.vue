<script setup lang="ts">
defineProps<{
    name: string;
}>();
</script>

<template>
    <span
        class="inline-flex max-w-[140px] items-center px-1.5 py-0.5"
        style="
            border: 1px solid var(--bm-border);
            border-radius: var(--bm-radius);
            background-color: var(--bm-surface-2);
            color: var(--bm-text-3);
            font-family: var(--bm-font-mono);
            font-size: 10px;
            font-weight: 500;
        "
        :title="name"
    >
        <span class="truncate">{{ name }}</span>
    </span>
</template>
