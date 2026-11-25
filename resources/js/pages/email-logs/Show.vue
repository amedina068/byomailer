<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowLeft } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as emailLogsIndex,
    show as emailLogsShow,
} from '@/routes/email-logs';
import type { BreadcrumbItem } from '@/types';

interface EmailLog {
    id: number;
    provider: string;
    operation: string;
    recipient: string | null;
    message_id: string | null;
    status: string;
    error: string | null;
    request_payload: Record<string, unknown> | null;
    response_payload: Record<string, unknown> | null;
    sns_payload: Record<string, unknown> | null;
    logged_at: string;
    created_at: string;
}

const props = defineProps<{
    emailLog: EmailLog;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Email Logs', href: emailLogsIndex.url() },
    {
        title: `Log #${props.emailLog.id}`,
        href: emailLogsShow.url(props.emailLog.id),
    },
];

const statusColor = (status: string): string => {
    const map: Record<string, string> = {
        sent: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
        success: 'bg-teal-500/10 text-teal-700 dark:text-teal-400',
        delivered: 'bg-green-500/10 text-green-700 dark:text-green-400',
        opened: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        failed: 'bg-red-500/10 text-red-700 dark:text-red-400',
        bounced: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
        complained: 'bg-yellow-500/10 text-yellow-800 dark:text-yellow-400',
    };
    return map[status] ?? 'bg-muted text-muted-foreground';
};

const formatDateTime = (value?: string | null): string => {
    if (!value) {
        return '\u2014';
    }
    const d = new Date(value);
    return (
        d.toLocaleDateString() +
        ' ' +
        d.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        })
    );
};

const formatJson = (value: Record<string, unknown> | null): string => {
    if (!value) {
        return 'No data';
    }
    return JSON.stringify(value, null, 2);
};

const hasRequestPayload = computed(
    () => props.emailLog.request_payload !== null,
);
const hasResponsePayload = computed(
    () => props.emailLog.response_payload !== null,
);
const hasSnsPayload = computed(() => props.emailLog.sns_payload !== null);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Email Log #${emailLog.id}`" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-3">
                            <h2 class="text-xl font-semibold text-foreground">
                                Email Log
                                <span class="font-mono text-muted-foreground"
                                    >#{{ emailLog.id }}</span
                                >
                            </h2>
                            <span
                                class="inline-flex items-center rounded-md px-2 py-0.5 font-mono text-xs font-medium"
                                :class="statusColor(emailLog.status)"
                            >
                                {{ emailLog.status }}
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ emailLog.operation }} via {{ emailLog.provider }}
                        </p>
                    </div>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="emailLogsIndex.url()">
                            <PhArrowLeft class="mr-1.5 h-4 w-4" />
                            Back to logs
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <h3
                    class="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Details
                </h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="text-xs text-muted-foreground">Provider</p>
                        <p class="font-mono text-sm font-medium">
                            {{ emailLog.provider }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Operation</p>
                        <p class="font-mono text-sm font-medium">
                            {{ emailLog.operation }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Status</p>
                        <span
                            class="inline-flex items-center rounded-md px-2 py-0.5 font-mono text-xs font-medium"
                            :class="statusColor(emailLog.status)"
                        >
                            {{ emailLog.status }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Recipient</p>
                        <p class="text-sm font-medium">
                            {{ emailLog.recipient ?? '\u2014' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Message ID</p>
                        <p class="font-mono text-sm font-medium break-all">
                            {{ emailLog.message_id ?? '\u2014' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Logged at</p>
                        <p class="font-mono text-sm font-medium">
                            {{ formatDateTime(emailLog.logged_at) }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="emailLog.error"
                    class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                >
                    <p class="text-xs font-semibold uppercase">Error</p>
                    <p class="mt-1">{{ emailLog.error }}</p>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <h3
                    class="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Request Payload
                </h3>
                <pre
                    v-if="hasRequestPayload"
                    class="overflow-x-auto rounded-md border border-border bg-background p-4 font-mono text-xs text-foreground"
                    >{{ formatJson(emailLog.request_payload) }}</pre
                >
                <p v-else class="text-sm text-muted-foreground">
                    No request payload recorded.
                </p>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <h3
                    class="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Response Payload
                </h3>
                <pre
                    v-if="hasResponsePayload"
                    class="overflow-x-auto rounded-md border border-border bg-background p-4 font-mono text-xs text-foreground"
                    >{{ formatJson(emailLog.response_payload) }}</pre
                >
                <p v-else class="text-sm text-muted-foreground">
                    No response payload recorded.
                </p>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <h3
                    class="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    SNS Payload
                </h3>
                <pre
                    v-if="hasSnsPayload"
                    class="overflow-x-auto rounded-md border border-border bg-background p-4 font-mono text-xs text-foreground"
                    >{{ formatJson(emailLog.sns_payload) }}</pre
                >
                <p v-else class="text-sm text-muted-foreground">
                    No SNS payload recorded.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
