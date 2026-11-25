<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { PhCursorClick } from '@phosphor-icons/vue';
import { computed } from 'vue';

import LinkClickRow from '@/components/links/LinkClickRow.vue';
import type { LinkClickSummary } from '@/lib/linkClicks';

const props = defineProps<{
    linkClicks?: LinkClickSummary;
    activeKey?: string | null;
    selectHint: string;
}>();

const emit = defineEmits<{
    select: [key: string];
}>();

const links = computed(() => props.linkClicks?.links ?? []);

const headers = [
    { label: 'Link', align: 'w-full text-left' },
    { label: 'Unique contacts', align: 'text-right' },
    { label: 'Total clicks', align: 'text-right' },
    { label: 'Click rate', align: 'text-right' },
];
</script>

<template>
    <div
        class="overflow-hidden rounded-(--bm-radius-md) border border-(--bm-border) bg-(--bm-surface) shadow-(--bm-shadow-xs)"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-(--bm-border) px-5 py-3"
        >
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-semibold text-(--bm-text-1)">Links</h2>
                <span
                    v-if="linkClicks"
                    class="rounded-(--bm-radius-sm) border border-(--bm-border) bg-(--bm-base) px-1.5 py-0.5 font-(family-name:--bm-font-mono) text-[10px] text-(--bm-text-3) tabular-nums"
                >
                    {{ links.length.toLocaleString() }}
                </span>
            </div>
            <p v-if="links.length" class="text-xs text-(--bm-text-3)">
                {{ selectHint }}
            </p>
        </div>

        <Deferred data="linkClicks">
            <template #fallback>
                <div class="space-y-2 p-4" aria-busy="true">
                    <div
                        v-for="n in 3"
                        :key="n"
                        class="h-9 animate-pulse rounded-(--bm-radius) bg-(--bm-surface-2)"
                    />
                </div>
            </template>

            <template #rescue>
                <p class="px-5 py-8 text-center text-sm text-(--bm-text-3)">
                    The link clicks did not load. Reload the page.
                </p>
            </template>

            <div
                v-if="!links.length"
                class="flex flex-col items-center gap-2 px-5 py-10 text-center"
            >
                <PhCursorClick :size="20" class="text-(--bm-text-4)" />
                <p class="text-sm font-medium text-(--bm-text-2)">
                    No link clicks yet
                </p>
                <p class="max-w-md text-xs text-pretty text-(--bm-text-3)">
                    Clicks show here after contacts open a link. To track a link
                    by name, select it in the editor and fill in
                    <span class="font-medium text-(--bm-text-2)">Link name</span
                    >. The editor saves the name as a
                    <code class="font-(family-name:--bm-font-mono)"
                        >data-bm-link</code
                    >
                    attribute.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-(--bm-border-strong) bg-(--bm-surface-2)"
                        >
                            <th
                                v-for="header in headers"
                                :key="header.label"
                                scope="col"
                                class="px-5 py-2.5 text-[10px] font-semibold tracking-widest whitespace-nowrap text-(--bm-text-3) uppercase"
                                :class="header.align"
                            >
                                {{ header.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <LinkClickRow
                            v-for="item in links"
                            :key="item.key"
                            :item="item"
                            :active="item.key === activeKey"
                            @select="(key: string) => emit('select', key)"
                        />
                    </tbody>
                </table>
            </div>
        </Deferred>

        <p
            class="border-t border-(--bm-border) px-5 py-2.5 text-xs text-(--bm-text-3)"
        >
            Clicks can include automated link scanners, for example Microsoft
            Safe Links.
        </p>
    </div>
</template>
