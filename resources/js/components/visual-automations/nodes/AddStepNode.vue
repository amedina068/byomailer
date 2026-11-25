<script setup lang="ts">
import type { NodeProps } from '@vue-flow/core';
import { Handle, Position } from '@vue-flow/core';
import { Plus } from 'lucide-vue-next';
import { inject } from 'vue';

import { builderKey } from '../builderContext';

const props = defineProps<
    NodeProps<{
        sourceId: string;
        sourceHandle: string | null;
        label?: string;
        insertOnEdgeId?: string;
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
    <div v-if="builder.isEditable" class="flex flex-col items-center gap-1">
        <Handle
            type="target"
            :position="Position.Top"
            class="!h-2 !w-2 !border-0 !bg-transparent"
        />

        <p v-if="data.label" class="text-xs font-medium text-muted-foreground">
            {{ data.label }}
        </p>

        <button
            type="button"
            aria-label="Add step"
            class="nodrag flex h-9 w-9 items-center justify-center rounded-full border-2 border-dashed border-border bg-card text-muted-foreground transition-colors hover:border-primary hover:text-primary"
            @click="handleClick"
        >
            <Plus class="h-4 w-4" />
        </button>
    </div>
</template>
