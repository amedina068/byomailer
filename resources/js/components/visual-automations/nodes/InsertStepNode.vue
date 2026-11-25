<script setup lang="ts">
import type { NodeProps } from '@vue-flow/core';
import { Plus } from 'lucide-vue-next';
import { inject } from 'vue';

import { builderKey } from '../builderContext';

const props = defineProps<
    NodeProps<{
        sourceId: string;
        sourceHandle: string | null;
        insertOnEdgeId: string;
    }>
>();

const builder = inject(builderKey)!;

const handleClick = () => {
    if (!builder.isEditable) {
        return;
    }

    builder.openAddStepModal({
        sourceId: props.data.sourceId,
        sourceHandle: props.data.sourceHandle,
        insertOnEdgeId: props.data.insertOnEdgeId,
    });
};
</script>

<template>
    <div
        v-if="builder.isEditable"
        class="flex w-[280px] items-center justify-center"
    >
        <button
            type="button"
            aria-label="Insert step"
            class="nodrag flex h-7 w-7 items-center justify-center rounded-full border border-dashed border-border/60 bg-card text-muted-foreground/40 transition-all hover:border-primary hover:text-primary hover:shadow-sm"
            @click="handleClick"
        >
            <Plus class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
