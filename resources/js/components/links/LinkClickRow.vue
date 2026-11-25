<script setup lang="ts">
import { PhArrowSquareOut } from '@phosphor-icons/vue';
import { computed } from 'vue';

import type { LinkClickItem } from '@/lib/linkClicks';

const props = defineProps<{
    item: LinkClickItem;
    active?: boolean;
}>();

const emit = defineEmits<{
    select: [key: string];
}>();

const primaryLabel = computed(
    () => props.item.name ?? props.item.url ?? 'Unknown link',
);

const secondaryUrl = computed(() => (props.item.name ? props.item.url : null));

const formatRate = (rate: number | null): string =>
    rate === null ? '—' : `${rate}%`;

const select = (): void => {
    emit('select', props.item.key);
};
</script>

<template>
    <tr
        class="cursor-pointer border-b border-(--bm-border) transition-colors hover:bg-(--bm-surface-2) focus-visible:bg-(--bm-surface-2) focus-visible:outline-none"
        :class="{ 'bg-(--bm-accent-bright-dim)': active }"
        role="button"
        tabindex="0"
        :aria-pressed="active ? 'true' : 'false'"
        @click="select"
        @keydown.enter.prevent="select"
        @keydown.space.prevent="select"
    >
        <td class="max-w-0 min-w-48 px-5 py-3">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="w-0.5 shrink-0 self-stretch rounded-full"
                    :class="active ? 'bg-(--bm-accent)' : 'bg-transparent'"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p
                        class="truncate text-[13px] font-medium text-(--bm-text-1)"
                        :class="{
                            'font-(family-name:--bm-font-mono)': item.name,
                        }"
                        :title="primaryLabel"
                    >
                        {{ primaryLabel }}
                    </p>
                    <a
                        v-if="secondaryUrl"
                        :href="secondaryUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-0.5 flex min-w-0 items-center gap-1 text-xs text-(--bm-text-3) hover:text-(--bm-text-2) hover:underline"
                        :title="secondaryUrl"
                        @click.stop
                        @keydown.stop
                    >
                        <span class="truncate">{{ secondaryUrl }}</span>
                        <PhArrowSquareOut :size="12" class="shrink-0" />
                    </a>
                </div>
            </div>
        </td>
        <td
            class="px-5 py-3 text-right font-(family-name:--bm-font-mono) whitespace-nowrap text-(--bm-text-1) tabular-nums"
        >
            {{ item.unique_contacts.toLocaleString() }}
        </td>
        <td
            class="px-5 py-3 text-right font-(family-name:--bm-font-mono) whitespace-nowrap text-(--bm-text-2) tabular-nums"
        >
            {{ item.total_clicks.toLocaleString() }}
        </td>
        <td
            class="px-5 py-3 text-right font-(family-name:--bm-font-mono) whitespace-nowrap text-(--bm-text-2) tabular-nums"
        >
            {{ formatRate(item.click_rate) }}
        </td>
    </tr>
</template>
