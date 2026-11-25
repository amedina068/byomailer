<script setup lang="ts">
import { Form, Link, router } from '@inertiajs/vue3';
import { PhCheckCircle, PhPause, PhProhibit } from '@phosphor-icons/vue';
import { computed } from 'vue';

import WebhookStatusController from '@/actions/App/Http/Controllers/Settings/WebhookStatusController';
import { useRelativeTime } from '@/composables/useRelativeTime';

interface WebhookEndpointData {
    id: number;
    url: string;
    events: string[];
    active: boolean;
    disabled_reason: string | null;
    consecutive_failures: number;
    last_triggered_at: string | null;
}

const props = defineProps<{
    endpoint: WebhookEndpointData;
    viewUrl: string;
    autoFailuresReason: string;
}>();

const { formatRelative } = useRelativeTime();

const statusMeta = computed(() => {
    if (props.endpoint.active) {
        return { label: 'Active', icon: PhCheckCircle, fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' };
    }
    if (props.endpoint.disabled_reason === props.autoFailuresReason) {
        return {
            label: `Paused — ${props.endpoint.consecutive_failures} failures`,
            icon: PhPause,
            fg: 'var(--bm-warning)',
            bg: 'var(--bm-warning-dim)',
        };
    }
    return { label: 'Inactive', icon: PhProhibit, fg: 'var(--bm-text-3)', bg: 'var(--bm-surface-2)' };
});

const navigate = (): void => {
    router.visit(props.viewUrl);
};

const onHover = (event: Event, on: boolean): void => {
    (event.currentTarget as HTMLElement).style.backgroundColor = on ? 'var(--bm-surface-2)' : 'transparent';
};
</script>

<template>
    <tr
        class="group cursor-pointer transition-colors"
        style="border-bottom: 1px solid var(--bm-border)"
        @click="navigate"
        @mouseenter="(e) => onHover(e, true)"
        @mouseleave="(e) => onHover(e, false)"
    >
        <td class="px-4 py-3">
            <div class="flex flex-col gap-1">
                <Link
                    :href="viewUrl"
                    class="truncate rounded text-[13px] font-medium focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--bm-accent)]"
                    style="color: var(--bm-text-1)"
                    @click.stop
                    >{{ endpoint.url }}</Link
                >
                <div class="flex flex-wrap items-center gap-1">
                    <span
                        v-for="event in endpoint.events"
                        :key="event"
                        class="px-1.5 py-0.5 text-[10px]"
                        style="font-family: var(--bm-font-mono); background-color: var(--bm-surface-2); color: var(--bm-text-3); border-radius: 3px"
                    >
                        {{ event }}
                    </span>
                </div>
            </div>
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
        <td class="px-4 py-3 text-[11px] tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-4)">
            {{ formatRelative(endpoint.last_triggered_at, { emptyLabel: 'never', withYear: true }) }}
        </td>
        <td class="px-4 py-3 text-right" @click.stop>
            <Form v-bind="WebhookStatusController.form(endpoint.id)" v-slot="{ processing }">
                <input type="hidden" name="active" :value="endpoint.active ? '0' : '1'" />
                <button
                    type="submit"
                    :disabled="processing"
                    :aria-label="endpoint.active ? 'Disable endpoint' : 'Enable endpoint'"
                    class="px-2 py-1 text-[11px]"
                    style="font-family: var(--bm-font-mono); color: var(--bm-text-2); border: 1px solid var(--bm-border); border-radius: 3px"
                >
                    {{ endpoint.active ? 'Disable' : 'Enable' }}
                </button>
            </Form>
        </td>
    </tr>
</template>
