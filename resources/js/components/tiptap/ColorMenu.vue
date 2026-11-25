<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Editor } from '@tiptap/core';
import { PhPalette } from '@phosphor-icons/vue';
import { isValidHexColor, normalizeHexColor } from '@/components/tiptap/typography';
import { loadRecentColors, pushRecentColor } from '@/components/tiptap/recentColors';
import { useToolbarMenu } from '@/components/tiptap/useToolbarMenu';

const props = defineProps<{
    editor: Editor;
    disabled: boolean;
}>();

const { isOpen, rootRef, toggle, close } = useToolbarMenu();

const recents = ref<string[]>(loadRecentColors());
const textValue = ref<string>('');
const errorMessage = ref<string>('');

const currentColor = computed<string | null>(
    () => (props.editor.getAttributes('textStyle').color as string | null) ?? null,
);

const apply = (hex: string, options: { close?: boolean } = { close: true }): void => {
    const normalized = normalizeHexColor(hex);
    if (!normalized) {
        errorMessage.value = 'Invalid hex (use #RRGGBB).';
        return;
    }

    errorMessage.value = '';
    props.editor.chain().focus().setColor(normalized).run();
    recents.value = pushRecentColor(normalized);
    textValue.value = '';
    if (options.close) {
        close();
    }
};

const onTextSubmit = (): void => apply(textValue.value, { close: true });

const onNativeColorChange = (event: Event): void => {
    const value = (event.target as HTMLInputElement).value;
    if (isValidHexColor(value)) {
        apply(value, { close: false });
    }
};

const reset = (): void => {
    props.editor.chain().focus().unsetColor().run();
    errorMessage.value = '';
    close();
};
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            type="button"
            class="flex items-center gap-1 rounded-[var(--bm-radius-md)] p-1.5 transition hover:bg-muted disabled:opacity-50"
            :class="{ 'bg-muted': isOpen }"
            :disabled="disabled"
            title="Text color"
            @click="toggle"
        >
            <PhPalette weight="regular" class="h-4 w-4" />
            <span
                data-current-swatch
                class="h-2 w-4 rounded-[2px] border border-border"
                :style="{ backgroundColor: currentColor ?? '#000000' }"
            />
        </button>

        <div
            v-if="isOpen"
            data-color-popover
            class="absolute top-full left-0 z-30 mt-1 w-[16rem] rounded-[var(--bm-radius-md)] border border-border bg-background p-3 shadow-md"
        >
            <div class="flex items-center gap-2">
                <input
                    type="color"
                    :value="currentColor ?? '#000000'"
                    class="h-8 w-10 cursor-pointer rounded-[var(--bm-radius-md)] border border-border"
                    @change="onNativeColorChange"
                />
                <input
                    v-model="textValue"
                    type="text"
                    placeholder="#RRGGBB"
                    spellcheck="false"
                    class="flex-1 rounded-[var(--bm-radius-md)] border border-border bg-background px-2 py-1 font-mono text-xs"
                    @keydown.enter.prevent="onTextSubmit"
                />
            </div>

            <p
                v-if="errorMessage"
                class="mt-2 font-mono text-[11px] text-red-600 dark:text-red-400"
            >
                {{ errorMessage }}
            </p>

            <div v-if="recents.length" class="mt-3">
                <p class="mb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                    Recent
                </p>
                <div class="flex gap-1.5">
                    <button
                        v-for="hex in recents"
                        :key="hex"
                        type="button"
                        data-recent-swatch
                        class="h-5 w-5 cursor-pointer rounded-[var(--bm-radius-md)] border border-border"
                        :style="{ backgroundColor: hex }"
                        :title="hex"
                        @click="apply(hex)"
                    />
                </div>
            </div>

            <div class="mt-3 flex justify-end">
                <button
                    type="button"
                    data-reset
                    class="rounded-[var(--bm-radius-md)] px-2 py-1 text-xs text-muted-foreground hover:bg-muted"
                    @click="reset"
                >
                    Reset
                </button>
            </div>
        </div>
    </div>
</template>
