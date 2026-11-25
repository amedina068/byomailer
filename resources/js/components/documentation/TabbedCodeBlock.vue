<script setup lang="ts">
import { Copy, Check } from 'lucide-vue-next';
import { ref, computed } from 'vue';

import { Button } from '@/components/ui/button';
import { useCodeLanguage } from '@/composables/useCodeLanguage';
import { useHighlighter } from '@/composables/useHighlighter';

export interface Tab {
    label: string;
    lang: string;
    code: string;
}

const props = defineProps<{
    title: string;
    tabs: Tab[];
}>();

const activeLanguage = useCodeLanguage();
const { ready, highlight } = useHighlighter();

const activeIndex = computed(() => {
    const index = props.tabs.findIndex((t) => t.lang === activeLanguage.value);
    return index >= 0 ? index : 0;
});

const activeTab = computed(() => props.tabs[activeIndex.value]);

const highlightedHtml = computed(() => {
    if (!ready.value) {
        return '';
    }
    return highlight(activeTab.value.code, activeTab.value.lang);
});

const selectTab = (index: number) => {
    activeLanguage.value = props.tabs[index].lang;
};

const copied = ref(false);

const copyToClipboard = async () => {
    await navigator.clipboard.writeText(activeTab.value.code);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};
</script>

<template>
    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {{ title }}
        </h3>
        <div class="overflow-hidden rounded-lg bg-zinc-950">
            <!-- Tabs -->
            <div class="flex items-center gap-0 overflow-x-auto border-b border-zinc-800 px-2">
                <button
                    v-for="(tab, index) in tabs"
                    :key="tab.label"
                    class="shrink-0 px-3 py-2 text-xs font-medium transition-colors"
                    :class="index === activeIndex
                        ? 'border-b-2 border-zinc-400 text-zinc-100'
                        : 'text-zinc-500 hover:text-zinc-300'"
                    @click="selectTab(index)"
                >
                    {{ tab.label }}
                </button>

                <!-- Copy button -->
                <div class="ml-auto shrink-0 pr-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 w-7 p-0 text-zinc-500 hover:text-zinc-100"
                        @click="copyToClipboard"
                    >
                        <Check v-if="copied" class="h-3.5 w-3.5" />
                        <Copy v-else class="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            <!-- Code -->
            <div
                v-if="ready"
                class="shiki-wrapper overflow-x-auto p-4 text-sm [&_pre]:!bg-transparent [&_pre]:!p-0 [&_code]:!bg-transparent"
                v-html="highlightedHtml"
            />
            <pre v-else class="overflow-x-auto p-4 text-sm text-zinc-100"><code>{{ activeTab.code }}</code></pre>
        </div>
    </div>
</template>
