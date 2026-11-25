<script setup lang="ts">
import { computed } from 'vue';
import type { Editor } from '@tiptap/core';
import { PhCaretDown } from '@phosphor-icons/vue';
import { FONT_SIZES, type FontSizeKey } from '@/components/tiptap/typography';
import { useToolbarMenu } from '@/components/tiptap/useToolbarMenu';

const props = defineProps<{
    editor: Editor;
    disabled: boolean;
}>();

const { isOpen, rootRef, toggle, close } = useToolbarMenu();

const SIZE_ENTRIES = Object.entries(FONT_SIZES) as Array<[FontSizeKey, number]>;

const SCALE_LABELS: Record<FontSizeKey, string> = {
    xs: 'XS',
    s: 'S',
    m: 'M',
    l: 'L',
    xl: 'XL',
    '2xl': '2XL',
};

const currentValue = computed<string | null>(
    () => (props.editor.getAttributes('textStyle').fontSize as string | null) ?? null,
);

const currentLabel = computed<string>(() => {
    if (!currentValue.value) {
        return 'Default';
    }

    const px = Number.parseInt(currentValue.value, 10);
    const match = SIZE_ENTRIES.find(([, value]) => value === px);
    return match ? SCALE_LABELS[match[0]] : currentValue.value;
});

const select = (px: number | null): void => {
    if (px === null) {
        props.editor.chain().focus().unsetFontSize().run();
    } else {
        props.editor.chain().focus().setFontSize(`${px}px`).run();
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
            :title="`Font size — ${currentLabel}`"
            @click="toggle"
        >
            <span class="text-muted-foreground">Size:</span>
            <span class="min-w-[2rem] text-left">{{ currentLabel }}</span>
            <PhCaretDown weight="regular" class="h-3 w-3" />
        </button>

        <ul
            v-if="isOpen"
            role="listbox"
            class="absolute top-full left-0 z-30 mt-1 min-w-[8rem] rounded-[var(--bm-radius-md)] border border-border bg-background py-1 shadow-md"
        >
            <li
                role="option"
                :aria-selected="currentValue === null"
                class="flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5 text-sm hover:bg-muted"
                :class="{ 'bg-muted font-medium': currentValue === null }"
                @click="select(null)"
            >
                <span>Default</span>
            </li>
            <li
                v-for="[key, px] in SIZE_ENTRIES"
                :key="key"
                role="option"
                :aria-selected="currentValue === `${px}px`"
                class="flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5 text-sm hover:bg-muted"
                :class="{
                    'bg-muted font-medium': currentValue === `${px}px`,
                }"
                @click="select(px)"
            >
                <span>{{ SCALE_LABELS[key] }}</span>
                <span data-size-px class="font-mono text-xs text-muted-foreground">{{ px }}px</span>
            </li>
        </ul>
    </div>
</template>
