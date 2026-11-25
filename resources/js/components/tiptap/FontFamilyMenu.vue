<script setup lang="ts">
import { computed } from 'vue';
import type { Editor } from '@tiptap/core';
import { PhCaretDown } from '@phosphor-icons/vue';
import { FONT_FAMILIES } from '@/components/tiptap/typography';
import { useToolbarMenu } from '@/components/tiptap/useToolbarMenu';

const props = defineProps<{
    editor: Editor;
    disabled: boolean;
}>();

const { isOpen, rootRef, toggle, close } = useToolbarMenu();

const currentValue = computed<string | null>(
    () => (props.editor.getAttributes('textStyle').fontFamily as string | null) ?? null,
);

const currentLabel = computed<string>(() => {
    const match = FONT_FAMILIES.find((f) => f.value === currentValue.value);
    return match?.label ?? 'Font';
});

const select = (value: string | null): void => {
    if (value === null) {
        props.editor.chain().focus().unsetFontFamily().run();
    } else {
        props.editor.chain().focus().setFontFamily(value).run();
    }
    close();
};
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            type="button"
            class="flex items-center gap-1 rounded-[var(--bm-radius-md)] px-2 py-1.5 text-xs transition hover:bg-muted disabled:opacity-50"
            :class="{ 'bg-muted': isOpen }"
            :disabled="disabled"
            :title="`Font family — ${currentLabel}`"
            @click="toggle"
        >
            <span class="text-muted-foreground">Font:</span>
            <span class="max-w-[7rem] truncate">{{ currentLabel }}</span>
            <PhCaretDown weight="regular" class="h-3 w-3" />
        </button>

        <ul
            v-if="isOpen"
            role="listbox"
            class="absolute top-full left-0 z-30 mt-1 min-w-[12rem] rounded-[var(--bm-radius-md)] border border-border bg-background py-1 shadow-md"
        >
            <li
                v-for="family in FONT_FAMILIES"
                :key="family.label"
                role="option"
                :aria-selected="family.value === currentValue"
                :style="family.value ? { fontFamily: family.value } : undefined"
                class="cursor-pointer px-3 py-1.5 text-sm hover:bg-muted"
                :class="{
                    'bg-muted font-medium': family.value === currentValue,
                }"
                @click="select(family.value)"
            >
                {{ family.label }}
            </li>
        </ul>
    </div>
</template>
