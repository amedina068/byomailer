<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { NodeProps } from '@vue-flow/core';
import { Handle, Position } from '@vue-flow/core';
import { Clock, Users } from 'lucide-vue-next';
import { computed, inject } from 'vue';

import { builderKey } from '../builderContext';

const props = defineProps<
    NodeProps<{
        duration?: number;
        unit?: string;
        contactCount?: number;
    }>
>();

const builder = inject(builderKey)!;

const duration = computed(() => props.data.duration ?? 1);
const unit = computed(() => props.data.unit ?? 'days');

const setDuration = (event: Event) => {
    const value = Number((event.target as HTMLInputElement).value);
    builder.updateNodeData(props.id, {
        duration: Math.max(1, value),
    });
};

const setUnit = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    builder.updateNodeData(props.id, { unit: value });
};

const contactsUrl = computed(() =>
    builder.nodeContactsUrl ? builder.nodeContactsUrl(props.id) : null,
);

const navigateToContacts = () => {
    if (contactsUrl.value) {
        router.visit(contactsUrl.value);
    }
};

const removeNode = () => builder.confirmRemoveNode(props.id);
</script>

<template>
    <div
        class="w-[280px] rounded-xl border border-purple-500/30 bg-card p-4 text-left shadow-sm dark:border-purple-400/20"
    >
        <Handle
            type="target"
            :position="Position.Top"
            class="!h-3 !w-3 !border-2 !border-purple-500 !bg-background"
        />

        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <div
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-purple-500/10"
                >
                    <Clock class="h-4 w-4 text-purple-500" />
                </div>
                <p
                    class="text-xs font-semibold tracking-wider text-purple-500 uppercase"
                >
                    Delay
                </p>
                <button
                    v-if="data.contactCount"
                    type="button"
                    class="nodrag inline-flex items-center gap-0.5 text-[10px] font-medium text-slate-400 hover:text-primary"
                    @click="navigateToContacts"
                >
                    <Users class="h-3 w-3" />
                    {{ data.contactCount }}
                </button>
            </div>
            <button
                v-if="builder.isEditable"
                type="button"
                :disabled="!builder.isEditable"
                class="nodrag text-xs font-medium text-muted-foreground underline-offset-2 hover:underline"
                @click="removeNode"
            >
                Delete
            </button>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <label
                :for="`delay-duration-${id}`"
                class="text-xs font-medium text-muted-foreground"
            >
                Wait
            </label>
            <input
                :id="`delay-duration-${id}`"
                type="number"
                min="1"
                :disabled="!builder.isEditable"
                class="nodrag w-16 rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :value="duration"
                @change="setDuration"
            />
            <select
                aria-label="Delay unit"
                :disabled="!builder.isEditable"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :value="unit"
                @change="setUnit"
            >
                <option value="minutes">Minutes</option>
                <option value="hours">Hours</option>
                <option value="days">Days</option>
            </select>
        </div>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!h-3 !w-3 !border-2 !border-purple-500 !bg-background"
        />
    </div>
</template>
