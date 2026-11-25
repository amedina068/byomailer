<script setup lang="ts">
import { computed } from 'vue';
import type { Editor } from '@tiptap/core';
import { PhCaretDown } from '@phosphor-icons/vue';
import { useToolbarMenu } from '@/components/tiptap/useToolbarMenu';

type BlockKey = 'paragraph' | 'h1' | 'h2' | 'h3';

const props = defineProps<{
    editor: Editor;
    disabled: boolean;
}>();

const { isOpen, rootRef, toggle, close } = useToolbarMenu();

const OPTIONS: Array<{ key: BlockKey; label: string; level?: 1 | 2 | 3 }> = [
    { key: 'paragraph', label: 'Paragraph' },
    { key: 'h1', label: 'Heading 1', level: 1 },
    { key: 'h2', label: 'Heading 2', level: 2 },
    { key: 'h3', label: 'Heading 3', level: 3 },
];

const activeKey = computed<BlockKey>(() => {
    if (props.editor.isActive('heading', { level: 1 })) return 'h1';
    if (props.editor.isActive('heading', { level: 2 })) return 'h2';
    if (props.editor.isActive('heading', { level: 3 })) return 'h3';
    return 'paragraph';
});

const activeLabel = computed<string>(
    () => OPTIONS.find((o) => o.key === activeKey.value)?.label ?? 'Paragraph',
);

const select = (option: (typeof OPTIONS)[number]): void => {
    if (option.level === undefined) {
        props.editor.chain().focus().setParagraph().run();
    } else {
        props.editor.chain().focus().setHeading({ level: option.level }).run();
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
            :title="`Block type — ${activeLabel}`"
            @click="toggle"
        >
            <span class="min-w-[5rem] text-left">{{ activeLabel }}</span>
            <PhCaretDown weight="regular" class="h-3 w-3" />
        </button>

        <ul
            v-if="isOpen"
            role="listbox"
            class="absolute top-full left-0 z-30 mt-1 min-w-[8rem] rounded-[var(--bm-radius-md)] border border-border bg-background py-1 shadow-md"
        >
            <li
                v-for="option in OPTIONS"
                :key="option.key"
                role="option"
                :aria-selected="option.key === activeKey"
                class="cursor-pointer px-3 py-1.5 text-sm hover:bg-muted"
                :class="{ 'bg-muted font-medium': option.key === activeKey }"
                @click="select(option)"
            >
                {{ option.label }}
            </li>
        </ul>
    </div>
</template>
