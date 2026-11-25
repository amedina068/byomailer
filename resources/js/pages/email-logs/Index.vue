<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    logged_at: string;
    created_at: string;
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Filters {
    status?: string;
    operation?: string;
    search?: string;
}

const props = defineProps<{
    emailLogs: EmailLog[];
    pagination: Pagination;
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Email Logs',
        href: emailLogsIndex.url(),
    },
];

const searchQuery = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? '');
const operationFilter = ref(props.filters.operation ?? '');

const hasLogs = computed(() => props.emailLogs.length > 0);
const hasActiveFilters = computed(
    () =>
        Boolean(props.filters.search) ||
        Boolean(props.filters.status) ||
        Boolean(props.filters.operation),
);

const statusOptions = [
    'sent',
    'success',
    'failed',
    'delivered',
    'bounced',
    'complained',
    'opened',
];
const operationOptions = [
    'SendEmail',
    'SendRawEmail',
    'SendBulkEmail',
    'SendTemplatedEmail',
    'CreateEmailTemplate',
    'DeleteEmailTemplate',
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

const formatDate = (value?: string | null): string => {
    if (!value) {
        return '\u2014';
    }
    const d = new Date(value);
    return (
        d.toLocaleDateString() +
        ' ' +
        d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    );
};

const truncate = (value: string | null, length: number = 40): string => {
    if (!value) {
        return '\u2014';
    }
    return value.length > length ? value.slice(0, length) + '\u2026' : value;
};

/** Starts with the visible operation text, so voice control finds the link. */
const logLinkLabel = (log: EmailLog): string =>
    `${log.operation}, ${log.status}${log.recipient ? `, to ${log.recipient}` : ''}`;

const applyFilters = () => {
    const params: Record<string, string> = {};
    if (searchQuery.value) {
        params.search = searchQuery.value;
    }
    if (statusFilter.value) {
        params.status = statusFilter.value;
    }
    if (operationFilter.value) {
        params.operation = operationFilter.value;
    }

    router.get(emailLogsIndex.url(), params, {
        preserveState: true,
        replace: true,
    });
};

let debounceTimer: ReturnType<typeof setTimeout>;
watch(searchQuery, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

watch([statusFilter, operationFilter], applyFilters);

const clearFilters = () => {
    clearTimeout(debounceTimer);
    searchQuery.value = '';
    statusFilter.value = '';
    operationFilter.value = '';
    router.get(
        emailLogsIndex.url(),
        {},
        { preserveState: true, replace: true },
    );
};

const goToPage = (url: string | null) => {
    if (url) {
        router.get(url, {}, { preserveState: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Email Logs" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold text-foreground">
                        Email Logs
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Track all SES API requests, responses, and delivery
                        events.
                    </p>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <Input
                        v-model="searchQuery"
                        type="search"
                        aria-label="Search email logs"
                        placeholder="Search recipient or message ID..."
                        class="w-full sm:w-64"
                    />
                    <select
                        v-model="statusFilter"
                        aria-label="Filter by status"
                        class="h-9 rounded-md border border-border bg-background px-3 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="">All statuses</option>
                        <option v-for="s in statusOptions" :key="s" :value="s">
                            {{ s }}
                        </option>
                    </select>
                    <select
                        v-model="operationFilter"
                        aria-label="Filter by operation"
                        class="h-9 rounded-md border border-border bg-background px-3 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="">All operations</option>
                        <option
                            v-for="op in operationOptions"
                            :key="op"
                            :value="op"
                        >
                            {{ op }}
                        </option>
                    </select>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead
                            class="bg-muted/50 text-left tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Operation</th>
                                <th scope="col" class="px-4 py-3">Recipient</th>
                                <th scope="col" class="px-4 py-3">
                                    Message ID
                                </th>
                                <th scope="col" class="px-4 py-3">Error</th>
                                <th scope="col" class="px-4 py-3">Logged At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="log in props.emailLogs"
                                :key="log.id"
                                class="cursor-pointer bg-card/50 transition-colors duration-100 hover:bg-muted/30"
                                @click="router.get(emailLogsShow.url(log.id))"
                            >
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-md px-2 py-0.5 font-mono text-xs font-medium"
                                        :class="statusColor(log.status)"
                                    >
                                        {{ log.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    <Link
                                        :href="emailLogsShow(log.id)"
                                        :aria-label="logLinkLabel(log)"
                                        class="rounded focus-visible:ring-1 focus-visible:ring-[var(--bm-accent)] focus-visible:outline-none"
                                        @click.stop
                                        >{{ log.operation }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    {{ log.recipient ?? '\u2014' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ truncate(log.message_id, 20) }}
                                </td>
                                <td class="px-4 py-3 text-destructive">
                                    {{ truncate(log.error, 30) }}
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                >
                                    {{ formatDate(log.logged_at) }}
                                </td>
                            </tr>
                            <tr v-if="!hasLogs">
                                <td
                                    colspan="6"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    <template v-if="hasActiveFilters">
                                        No email logs match these filters.
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            class="h-auto px-1"
                                            @click="clearFilters"
                                            >Clear filters</Button
                                        >
                                    </template>
                                    <template v-else>
                                        No email logs yet. Logs show here after
                                        the app sends a request to SES.
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="pagination.last_page > 1"
                    class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
                >
                    <span class="font-mono">
                        Page {{ pagination.current_page }} of
                        {{ pagination.last_page }} ({{ pagination.total }}
                        total)
                    </span>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Previous page"
                            :disabled="!pagination.prev_page_url"
                            @click="goToPage(pagination.prev_page_url)"
                        >
                            <PhCaretLeft class="h-4 w-4" />
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label="Next page"
                            :disabled="!pagination.next_page_url"
                            @click="goToPage(pagination.next_page_url)"
                        >
                            <PhCaretRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
