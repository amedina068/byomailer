<script setup lang="ts">
import { Copy, Check } from 'lucide-vue-next';
import { ref, computed } from 'vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useHighlighter } from '@/composables/useHighlighter';

const props = defineProps<{
    title: string;
    code: string;
    lang?: string;
    badge?: string;
    badgeClass?: string;
    empty?: boolean;
}>();

const { ready, highlight } = useHighlighter();

const highlightedHtml = computed(() => {
    if (!ready.value || props.empty || !props.code) {
        return '';
    }
    return highlight(props.code, props.lang || 'json');
});

const copied = ref(false);

const copyToClipboard = async () => {
    await navigator.clipboard.writeText(props.code);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};
</script>

<template>
    <div>
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {{ title }}
            </h3>
            <Badge
                v-if="badge"
                :class="badgeClass"
                class="font-mono text-xs"
            >
                {{ badge }}
            </Badge>
        </div>
        <div class="relative overflow-hidden rounded-lg bg-zinc-950">
            <div
                v-if="!empty && ready"
                class="shiki-wrapper overflow-x-auto p-4 text-sm [&_pre]:!bg-transparent [&_pre]:!p-0 [&_code]:!bg-transparent"
                v-html="highlightedHtml"
            />
            <pre
                v-else-if="!empty"
                class="overflow-x-auto p-4 text-sm text-zinc-100"
            ><code>{{ code }}</code></pre>
            <pre
                v-else
                class="overflow-x-auto p-4 text-sm text-zinc-400 italic"
            ><code>No content</code></pre>
            <Button
                v-if="!empty"
                variant="ghost"
                size="sm"
                class="absolute right-2 top-2 h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100"
                @click="copyToClipboard"
            >
                <Check v-if="copied" class="h-4 w-4" />
                <Copy v-else class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
