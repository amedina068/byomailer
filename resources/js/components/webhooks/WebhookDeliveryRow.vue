<script setup lang="ts">
import { PhCheckCircle, PhClock, PhWarning } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { useRelativeTime } from '@/composables/useRelativeTime';

interface WebhookDeliveryData {
    id: number;
    uuid: string;
    event_type: string;
    status: string;
    attempts: number;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    payload: Record<string, unknown>;
    created_at: string | null;
}

const props = defineProps<{
    delivery: WebhookDeliveryData;
}>();

const { formatRelative } = useRelativeTime();

const formatDeliveredAt = (value?: string | null): string => formatRelative(value, { emptyLabel: '—' });

const statusMeta = computed(() => {
    switch (props.delivery.status) {
        case 'success':
            return { label: 'Success', icon: PhCheckCircle, fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' };
        case 'failed':
            return { label: 'Failed', icon: PhWarning, fg: 'var(--bm-error)', bg: 'var(--bm-error-dim)' };
        default:
            return { label: 'Pending', icon: PhClock, fg: 'var(--bm-warning)', bg: 'var(--bm-warning-dim)' };
    }
});

const prettyPayload = computed(() => JSON.stringify(props.delivery.payload, null, 2));

const truncated = computed(() => (props.delivery.response_body?.length ?? 0) >= 2048);
</script>

<template>
    <tr style="border-bottom: 1px solid var(--bm-border)">
        <td class="px-4 py-3">
            <span class="text-[11px]" style="font-family: var(--bm-font-mono); color: var(--bm-text-2)">
                {{ delivery.event_type }}
            </span>
        </td>
        <td class="px-4 py-3">
            <span
                class="inline-flex items-center gap-1.5 px-2 py-0.5"
                :style="{ backgroundColor: statusMeta.bg, color: statusMeta.fg, fontFamily: 'var(--bm-font-mono)', fontSize: '10px', fontWeight: 500, borderRadius: '3px' }"
                :aria-label="`Status: ${statusMeta.label}`"
            >
                <component :is="statusMeta.icon" :size="11" />
                {{ statusMeta.label }}
            </span>
        </td>
        <td class="px-4 py-3 tabular-nums text-[11px]" style="font-family: var(--bm-font-mono); color: var(--bm-text-3)">
            {{ delivery.response_status ?? '—' }}
        </td>
        <td class="px-4 py-3 tabular-nums text-[11px]" style="font-family: var(--bm-font-mono); color: var(--bm-text-4)">
            {{ delivery.attempts }}
        </td>
        <td class="px-4 py-3 tabular-nums text-[11px]" style="font-family: var(--bm-font-mono); color: var(--bm-text-4)">
            {{ formatDeliveredAt(delivery.created_at) }}
        </td>
        <td class="px-4 py-3">
            <details>
                <summary
                    class="cursor-pointer text-[11px]"
                    style="color: var(--bm-text-3)"
                    :aria-label="`Details for ${delivery.event_type} delivery ${formatDeliveredAt(delivery.created_at)}`"
                >
                    Details
                </summary>
                <div class="mt-2 flex flex-col gap-2">
                    <div>
                        <p class="mb-1 text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Payload</p>
                        <pre class="overflow-x-auto p-2 text-[11px]" style="background-color: var(--bm-surface-2); border-radius: 3px; color: var(--bm-text-2)">{{ prettyPayload }}</pre>
                    </div>
                    <div v-if="delivery.response_body">
                        <p class="mb-1 text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">
                            Response<span v-if="truncated"> (truncated to 2 KB)</span>
                        </p>
                        <pre class="overflow-x-auto p-2 text-[11px]" style="background-color: var(--bm-surface-2); border-radius: 3px; color: var(--bm-text-2)">{{ delivery.response_body }}</pre>
                    </div>
                    <div v-if="delivery.error">
                        <p class="mb-1 text-[10px] uppercase tracking-wider" style="color: var(--bm-error)">Error</p>
                        <pre class="overflow-x-auto p-2 text-[11px]" style="background-color: var(--bm-surface-2); border-radius: 3px; color: var(--bm-error)">{{ delivery.error }}</pre>
                    </div>
                </div>
            </details>
        </td>
    </tr>
</template>
