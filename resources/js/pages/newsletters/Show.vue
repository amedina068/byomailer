<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { PhPencilSimple } from '@phosphor-icons/vue';
import { computed, nextTick, ref, watch } from 'vue';

import { updateTitle as newsletterUpdateTitle } from '@/actions/App/Http/Controllers/NewsletterController';
import AbTestResults from '@/components/newsletters/AbTestResults.vue';
import NewsletterActions from '@/components/newsletters/NewsletterActions.vue';
import NewsletterRecipients, {
    type PaginatedRecipients,
} from '@/components/newsletters/NewsletterRecipients.vue';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    filterToSentence,
    type FilterSentenceContext,
} from '@/lib/filterSentence';
import type { LinkClickSummary } from '@/lib/linkClicks';
import { importMethod as contactsImport } from '@/routes/contacts';
import { show as newsletterShow } from '@/routes/newsletters';
import type {
    NewsletterFilter as Filter,
    NewsletterStatusOption,
} from '@/types/newsletters';

interface NewsletterMetrics {
    total: number;
    sent: number;
    delivered: number;
    pending: number;
    opened: number;
    clicked: number;
    bounced: number;
    unsubscribed: number;
    complained: number;
    delivery_rate: number | null;
    open_rate: number | null;
    click_rate: number | null;
    unsubscribe_rate: number | null;
}

interface ListData {
    id: number;
    name: string;
    data?: { id: number; name: string };
}

interface AbVariantMetrics {
    total: number;
    opened_count: number;
    clicked_count: number;
    open_rate: number | null;
    click_rate: number | null;
}

interface NewsletterData {
    id: number;
    title: string;
    subject: string;
    preview_text?: string | null;
    status: string;
    is_mutable: boolean;
    html_content: string;
    html_content_b?: string | null;
    text_content: string;
    from_email?: string | null;
    from_name?: string | null;
    sent_at?: string | null;
    list?: ListData | null;
    email_identity?: { id: number; email: string; name?: string | null } | null;
    filters?: Filter[];
    metrics: NewsletterMetrics;
    ab_test_enabled?: boolean | null;
    ab_test_winner_variant?: string | null;
    ab_metrics?: { a: AbVariantMetrics; b: AbVariantMetrics } | null;
}

const props = defineProps<{
    newsletter: NewsletterData;
    newsletterStatuses: NewsletterStatusOption[];
    recipients: PaginatedRecipients;
    recipientStatusFilter: string;
    recipientStatusOptions: string[];
    recipientLinkFilter?: string | null;
    linkClicks?: LinkClickSummary;
}>();

const breadcrumbs = [
    { title: 'Newsletters', href: newsletterShow(props.newsletter.id).url },
    {
        title: props.newsletter.title || props.newsletter.subject,
        href: newsletterShow(props.newsletter.id).url,
    },
];

const editingTitle = ref(false);
const titleInput = ref<HTMLInputElement | null>(null);
const titleValue = ref(props.newsletter.title);

const startEditingTitle = async (): Promise<void> => {
    titleValue.value = props.newsletter.title;
    editingTitle.value = true;
    await nextTick();
    titleInput.value?.focus();
    titleInput.value?.select();
};

const saveTitle = (): void => {
    const newTitle = titleValue.value.trim();
    if (!newTitle || newTitle === props.newsletter.title) {
        editingTitle.value = false;
        return;
    }

    router.patch(
        newsletterUpdateTitle(props.newsletter.id).url,
        { title: newTitle },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingTitle.value = false;
            },
            onError: () => {
                editingTitle.value = false;
            },
        },
    );
};

const cancelEditingTitle = (): void => {
    titleValue.value = props.newsletter.title;
    editingTitle.value = false;
};

const formatDateTime = (value?: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';

const formatNumber = (value: number | null | undefined): string =>
    (value ?? 0).toLocaleString();

const metrics = computed(
    () =>
        props.newsletter.metrics ?? {
            total: 0,
            sent: 0,
            delivered: 0,
            pending: 0,
            opened: 0,
            clicked: 0,
            bounced: 0,
            unsubscribed: 0,
            complained: 0,
            delivery_rate: null,
            open_rate: null,
            click_rate: null,
            unsubscribe_rate: null,
        },
);

const formatRate = (rate: number | null): string =>
    rate !== null ? `${rate}%` : '—';

const progressPercent = computed(() => {
    const total = metrics.value.total;
    if (!total) {
        return 0;
    }

    return Math.round((metrics.value.sent / total) * 100);
});

const totalRecipients = computed(
    () => metrics.value.total || props.recipients.total,
);

const unsubscribedCount = computed(() => metrics.value.unsubscribed);

// The server sends the label. The colors stay keyed by the status value.
const NEWSLETTER_STATUS_COLORS: Record<string, { fg: string; bg: string }> = {
    sent: { fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' },
    queued: { fg: 'var(--bm-info)', bg: 'var(--bm-info-dim)' },
    scheduled: { fg: 'var(--bm-warning)', bg: 'var(--bm-warning-dim)' },
    ab_testing: { fg: 'var(--bm-accent)', bg: 'var(--bm-accent-bright-dim)' },
    failed: { fg: 'var(--bm-error)', bg: 'var(--bm-error-dim)' },
    draft: { fg: 'var(--bm-text-3)', bg: 'var(--bm-surface-2)' },
};

const newsletterStatusMeta = computed(() => {
    const status = props.newsletter.status;
    const label =
        props.newsletterStatuses.find((option) => option.value === status)
            ?.label ?? status;

    return {
        label,
        ...(NEWSLETTER_STATUS_COLORS[status] ?? NEWSLETTER_STATUS_COLORS.draft),
    };
});

const metricCards = computed(() => [
    { label: 'Sent', value: formatNumber(metrics.value.sent) },
    { label: 'Deliverability', value: formatRate(metrics.value.delivery_rate) },
    { label: 'Open rate', value: formatRate(metrics.value.open_rate) },
    { label: 'Click rate', value: formatRate(metrics.value.click_rate) },
    { label: 'Unsub rate', value: formatRate(metrics.value.unsubscribe_rate) },
    { label: 'Pending', value: formatNumber(metrics.value.pending) },
    { label: 'Bounced', value: formatNumber(metrics.value.bounced) },
    { label: 'Complained', value: formatNumber(metrics.value.complained) },
]);

const listId = computed(
    () => props.newsletter.list?.id ?? props.newsletter.list?.data?.id,
);

const filterSentenceContext = computed((): FilterSentenceContext => {
    const list = props.newsletter.list?.data ?? props.newsletter.list;

    return {
        lists: list ? [{ id: list.id, name: list.name }] : [],
        topics: [],
        newsletters: [],
    };
});

const importUrl = computed(() => {
    if (!listId.value) {
        return contactsImport().url;
    }
    return contactsImport({ query: { list_id: listId.value } }).url;
});

// While a send is in flight, poll the server so metrics and recipient
// statuses update live. Stops automatically once the newsletter settles —
// i.e. dispatch finished AND no recipients are still pending delivery.
const newsletterInProgress = computed(() => {
    const status = props.newsletter.status;
    if (status === 'queued' || status === 'ab_testing') {
        return true;
    }
    return status === 'sent' && metrics.value.pending > 0;
});

const { start: startPolling, stop: stopPolling } = usePoll(
    5000,
    { only: ['newsletter', 'recipients'] },
    { autoStart: false },
);

watch(
    newsletterInProgress,
    (inProgress) => {
        if (inProgress) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Newsletter · ${newsletter.subject}`" />

        <section class="space-y-5">
            <!-- Header -->
            <div
                class="rounded-[var(--bm-radius-md)] border"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                "
            >
                <div class="flex items-start justify-between gap-4 p-5">
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-[10px] font-semibold tracking-widest uppercase"
                            style="color: var(--bm-text-3)"
                        >
                            Title
                            <span style="color: var(--bm-text-4)"
                                >· internal</span
                            >
                        </p>
                        <div class="group mt-1 flex items-center gap-2">
                            <template v-if="editingTitle">
                                <input
                                    ref="titleInput"
                                    v-model="titleValue"
                                    type="text"
                                    class="w-full max-w-md rounded-[var(--bm-radius)] border px-2.5 py-1 text-xl font-semibold focus-visible:outline-none"
                                    style="
                                        background-color: var(--bm-base);
                                        border-color: var(--bm-border);
                                        color: var(--bm-text-1);
                                    "
                                    @blur="saveTitle"
                                    @keyup.enter="saveTitle"
                                    @keyup.escape="cancelEditingTitle"
                                />
                            </template>
                            <template v-else>
                                <h1
                                    class="truncate text-xl font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    {{ newsletter.title || '—' }}
                                </h1>
                                <button
                                    type="button"
                                    class="shrink-0 transition-opacity focus-visible:opacity-100 sm:pointer-fine:opacity-0 sm:pointer-fine:group-hover:opacity-100"
                                    title="Edit title"
                                    @click="startEditingTitle"
                                >
                                    <PhPencilSimple
                                        :size="16"
                                        style="color: var(--bm-text-3)"
                                    />
                                    <span class="sr-only">Edit title</span>
                                </button>
                            </template>
                        </div>
                        <p
                            class="mt-1.5 truncate text-sm"
                            style="color: var(--bm-text-3)"
                        >
                            {{ newsletter.subject }}
                        </p>
                        <p
                            v-if="newsletter.preview_text"
                            class="mt-0.5 truncate text-xs"
                            style="color: var(--bm-text-3); opacity: 0.7"
                            :title="newsletter.preview_text"
                        >
                            Preview: {{ newsletter.preview_text }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 px-2 py-0.5"
                            :style="{
                                backgroundColor: newsletterStatusMeta.bg,
                                color: newsletterStatusMeta.fg,
                                fontFamily: 'var(--bm-font-mono)',
                                fontSize: '10px',
                                fontWeight: 500,
                                borderRadius: '3px',
                            }"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :style="{
                                    backgroundColor: newsletterStatusMeta.fg,
                                }"
                            ></span>
                            {{ newsletterStatusMeta.label }}
                        </span>
                        <NewsletterActions
                            v-if="newsletter.is_mutable"
                            :newsletter="newsletter"
                        />
                    </div>
                </div>

                <!-- Meta row -->
                <dl
                    class="grid grid-cols-2 border-t lg:grid-cols-3"
                    style="border-color: var(--bm-border)"
                >
                    <div
                        class="px-5 py-3.5"
                        style="border-color: var(--bm-border)"
                    >
                        <dt
                            class="text-[10px] font-semibold tracking-widest uppercase"
                            style="color: var(--bm-text-3)"
                        >
                            Sent at
                        </dt>
                        <dd
                            class="mt-1 truncate text-sm tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ formatDateTime(newsletter.sent_at) }}
                        </dd>
                    </div>
                    <div
                        class="border-l px-5 py-3.5"
                        style="border-color: var(--bm-border)"
                    >
                        <dt
                            class="text-[10px] font-semibold tracking-widest uppercase"
                            style="color: var(--bm-text-3)"
                        >
                            From
                        </dt>
                        <dd
                            class="mt-1 truncate text-sm"
                            style="color: var(--bm-text-1)"
                        >
                            {{
                                newsletter.from_name ??
                                newsletter.email_identity?.name ??
                                '—'
                            }}
                            <span style="color: var(--bm-text-3)">
                                &lt;{{
                                    newsletter.from_email ??
                                    newsletter.email_identity?.email ??
                                    '—'
                                }}&gt;
                            </span>
                        </dd>
                    </div>
                    <div
                        class="border-t px-5 py-3.5 lg:border-t-0 lg:border-l"
                        style="border-color: var(--bm-border)"
                    >
                        <dt
                            class="text-[10px] font-semibold tracking-widest uppercase"
                            style="color: var(--bm-text-3)"
                        >
                            Unsubscribes
                        </dt>
                        <dd
                            class="mt-1 text-sm tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ formatNumber(unsubscribedCount) }}
                            <span style="color: var(--bm-text-3)"
                                >/ {{ formatNumber(totalRecipients) }}</span
                            >
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Delivery progress -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-5"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                "
            >
                <div class="flex items-baseline justify-between gap-4">
                    <p
                        class="text-[10px] font-semibold tracking-widest uppercase"
                        style="color: var(--bm-text-3)"
                    >
                        Delivery progress
                    </p>
                    <p
                        class="text-xs tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            color: var(--bm-text-3);
                        "
                    >
                        {{ formatNumber(metrics.sent) }} /
                        {{ formatNumber(metrics.total) }}
                    </p>
                </div>
                <div
                    class="mt-2 h-1.5 w-full overflow-hidden rounded-full"
                    style="background-color: var(--bm-elevated)"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        style="background-color: var(--bm-accent)"
                        :style="{ width: `${progressPercent}%` }"
                    ></div>
                </div>
            </div>

            <!-- Metrics -->
            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-8"
            >
                <div
                    v-for="card in metricCards"
                    :key="card.label"
                    class="rounded-[var(--bm-radius)] border px-3.5 py-3"
                    style="
                        background-color: var(--bm-surface);
                        border-color: var(--bm-border);
                        box-shadow: var(--bm-shadow-xs);
                    "
                >
                    <p
                        class="truncate text-[10px] font-semibold tracking-widest uppercase"
                        style="color: var(--bm-text-3)"
                    >
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1.5 text-xl font-semibold tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            color: var(--bm-text-1);
                        "
                    >
                        {{ card.value }}
                    </p>
                </div>
            </div>

            <!-- A/B test results -->
            <AbTestResults
                v-if="newsletter.ab_test_enabled && newsletter.ab_metrics"
                :newsletter-id="newsletter.id"
                :status="newsletter.status"
                :winner-variant="newsletter.ab_test_winner_variant ?? null"
                :metrics="newsletter.ab_metrics"
            />

            <!-- Content -->
            <div
                class="rounded-[var(--bm-radius-md)] border"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                    overflow: hidden;
                "
            >
                <p
                    class="border-b px-5 py-3 text-[10px] font-semibold tracking-widest uppercase"
                    style="
                        border-color: var(--bm-border);
                        color: var(--bm-text-3);
                    "
                >
                    Content
                </p>
                <div class="bg-white">
                    <HtmlPreview :html="newsletter.html_content" />
                </div>
            </div>

            <!-- Filters -->
            <div
                v-if="newsletter.filters?.length"
                class="rounded-[var(--bm-radius-md)] border p-5"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                "
            >
                <h2
                    class="text-sm font-semibold"
                    style="color: var(--bm-text-1)"
                >
                    Filters
                </h2>
                <p class="mt-0.5 text-sm" style="color: var(--bm-text-3)">
                    Recipients were narrowed down using these filters.
                </p>
                <div class="mt-3 space-y-2">
                    <div
                        v-for="(filter, index) in newsletter.filters"
                        :key="index"
                        class="rounded-[var(--bm-radius)] border px-3.5 py-2.5 text-sm"
                        style="
                            background-color: var(--bm-base);
                            border-color: var(--bm-border);
                            color: var(--bm-text-2);
                        "
                    >
                        {{
                            filterToSentence(
                                filter,
                                index,
                                filterSentenceContext,
                            )
                        }}
                    </div>
                </div>
            </div>

            <!-- Links and recipients -->
            <NewsletterRecipients
                :newsletter-id="newsletter.id"
                :recipients="recipients"
                :status-filter="recipientStatusFilter"
                :status-options="recipientStatusOptions"
                :link-filter="recipientLinkFilter"
                :link-clicks="linkClicks"
                :import-url="listId ? importUrl : null"
            />
        </section>
    </AppLayout>
</template>
