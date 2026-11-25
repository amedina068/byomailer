<script setup lang="ts">
import { PhFolders } from '@phosphor-icons/vue';
import { computed } from 'vue';

import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

interface ProjectOption {
    id: number;
    name: string;
}

const props = defineProps<{
    modelValue: number[];
    options: ProjectOption[];
    label?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number[]): void;
}>();

const menuLabel = computed(() => props.label ?? 'Projects');

const triggerLabel = computed(() => {
    const count = props.modelValue.length;

    if (count === 0) {
        return menuLabel.value;
    }

    return `${count} project${count === 1 ? '' : 's'}`;
});

const isSelected = (id: number): boolean => props.modelValue.includes(id);

const toggle = (id: number): void => {
    const next = isSelected(id)
        ? props.modelValue.filter((value) => value !== id)
        : [...props.modelValue, id];

    emit('update:modelValue', next);
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <slot name="trigger" :label="triggerLabel" :count="modelValue.length">
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 px-2.5 text-[12px] font-medium transition-colors hover:bg-[var(--bm-elevated)]"
                    :style="{
                        border: '1px solid var(--bm-border)',
                        borderRadius: 'var(--bm-radius)',
                        backgroundColor: 'var(--bm-surface-2)',
                        color: 'var(--bm-text-2)',
                    }"
                >
                    <PhFolders :size="14" />
                    {{ triggerLabel }}
                    <span
                        v-if="modelValue.length > 0"
                        class="tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            font-size: 10px;
                            color: var(--bm-text-4);
                        "
                    >
                        / {{ options.length }}
                    </span>
                </button>
            </slot>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-56">
            <DropdownMenuLabel>{{ menuLabel }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuCheckboxItem
                v-for="option in options"
                :key="option.id"
                :checked="isSelected(option.id)"
                @select.prevent="toggle(option.id)"
            >
                <span class="truncate">{{ option.name }}</span>
            </DropdownMenuCheckboxItem>
            <div
                v-if="options.length === 0"
                class="px-2 py-1.5 text-[12px]"
                style="color: var(--bm-text-4)"
            >
                No projects yet
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
