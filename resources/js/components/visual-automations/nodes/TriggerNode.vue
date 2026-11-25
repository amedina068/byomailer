<script setup lang="ts">
import type { NodeProps } from '@vue-flow/core';
import { Handle, Position } from '@vue-flow/core';
import { Zap } from 'lucide-vue-next';
import { computed, inject } from 'vue';

import { builderKey } from '../builderContext';
import { TRIGGER_LABELS, type SelectOption, type TriggerType } from '../types';

const props = defineProps<
    NodeProps<{
        trigger_type?: TriggerType;
        value?: number | null;
        contactCount?: number;
    }>
>();

const builder = inject(builderKey)!;

const triggerType = computed(
    () => props.data.trigger_type ?? 'contact_added_to_list',
);

const optionsForTrigger = (type: TriggerType): SelectOption[] => {
    switch (type) {
        case 'contact_added_to_list':
        case 'removed_from_list':
            return builder.lists;
        case 'tag_added':
        case 'removed_tag':
            return builder.tags;
        case 'added_to_sequence':
        case 'sequence_finished':
            return builder.sequences;
        default:
            return [];
    }
};

const currentOptions = computed(() => optionsForTrigger(triggerType.value));
const selectedValue = computed(() =>
    props.data.value != null ? String(props.data.value) : '',
);

const availableTriggerTypes = computed<TriggerType[]>(() => {
    const types: TriggerType[] = [];
    if (builder.lists.length) {
        types.push('contact_added_to_list', 'removed_from_list');
    }
    if (builder.tags.length) {
        types.push('tag_added', 'removed_tag');
    }
    if (builder.sequences.length) {
        types.push('added_to_sequence', 'sequence_finished');
    }
    return types.length ? types : (['contact_added_to_list'] as TriggerType[]);
});

const setTriggerType = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value as TriggerType;
    const options = optionsForTrigger(value);
    builder.updateNodeData(props.id, {
        trigger_type: value,
        value: options.length ? options[0].id : null,
    });
};

const setTriggerValue = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    builder.updateNodeData(props.id, {
        value: value === '' ? null : Number(value),
    });
};
</script>

<template>
    <div
        class="w-[280px] rounded-xl border border-amber-500/30 bg-card p-4 text-left shadow-sm dark:border-amber-400/20"
    >
        <div class="flex items-center gap-2">
            <div
                class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10"
            >
                <Zap class="h-4 w-4 text-amber-500" />
            </div>
            <div>
                <p
                    class="text-xs font-semibold tracking-wider text-amber-500 uppercase"
                >
                    Trigger
                </p>
            </div>
        </div>

        <div class="mt-3 grid gap-2 text-sm">
            <label
                :for="`trigger-type-${id}`"
                class="text-xs font-medium text-muted-foreground"
            >
                When
            </label>
            <select
                :id="`trigger-type-${id}`"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="triggerType"
                @change="setTriggerType"
            >
                <option
                    v-for="type in availableTriggerTypes"
                    :key="type"
                    :value="type"
                >
                    {{ TRIGGER_LABELS[type] }}
                </option>
            </select>
        </div>

        <div v-if="currentOptions.length" class="mt-3 grid gap-2 text-sm">
            <label
                :for="`trigger-target-${id}`"
                class="text-xs font-medium text-muted-foreground"
            >
                Target
            </label>
            <select
                :id="`trigger-target-${id}`"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="selectedValue"
                @change="setTriggerValue"
            >
                <option
                    v-for="option in currentOptions"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.name }}
                </option>
            </select>
        </div>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!h-3 !w-3 !border-2 !border-amber-500 !bg-background"
        />
    </div>
</template>
