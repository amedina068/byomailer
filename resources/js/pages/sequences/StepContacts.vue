<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhDownloadSimple } from '@phosphor-icons/vue';
import { computed } from 'vue';

import type {
    FilterOption,
    PaginatedContacts,
} from '@/components/contacts/PaginatedContactsTable.vue';
import PaginatedContactsTable from '@/components/contacts/PaginatedContactsTable.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    show as sequenceShow,
    index as sequencesIndex,
} from '@/routes/sequences';
import stepsRoutes from '@/routes/sequences/steps';
import { exportMethod as exportContactMails } from '@/routes/sequences/steps/contact-mails';
import stepContacts from '@/routes/sequences/steps/contacts';
import type { BreadcrumbItem } from '@/types';

type ContactFilter = 'all' | 'unsubscribed' | 'clicked';

interface Sequence {
    id: number;
    name: string;
}

interface StepMessage {
    subject?: string | null;
}

interface SequenceStep {
    id: number;
    message?: StepMessage | null;
}

type SendStatus =
    | 'pending'
    | 'sent'
    | 'delivered'
    | 'opened'
    | 'clicked'
    | 'bounced'
    | 'rejected'
    | 'complained'
    | 'unsubscribed'
    | 'failed'
    | 'skipped';

interface SequenceContact {
    id: number;
    name: string | null;
    email: string;
    unsubscribed_at?: string | null;
    step_unsubscribed_at?: string | null;
    send_date?: string | null;
    send_status: SendStatus;
    step_failure_reason?: string | null;
    created_at: string;
    latest_click?: {
        url?: string | null;
        clicked_at?: string | null;
    } | null;
}

interface StatusMeta {
    label: string;
    bg: string;
    fg: string;
}

const STATUS_META: Record<SendStatus, StatusMeta> = {
    pending: {
        label: 'Pending',
        bg: 'var(--bm-warning-dim)',
        fg: 'var(--bm-warning)',
    },
    sent: { label: 'Sent', bg: 'var(--bm-info-dim)', fg: 'var(--bm-info)' },
    delivered: {
        label: 'Delivered',
        bg: 'var(--bm-info-dim)',
        fg: 'var(--bm-info)',
    },
    opened: {
        label: 'Opened',
        bg: 'var(--bm-success-dim)',
        fg: 'var(--bm-success)',
    },
    clicked: {
        label: 'Clicked',
        bg: 'var(--bm-accent-bright-dim)',
        fg: 'var(--bm-accent)',
    },
    bounced: {
        label: 'Bounced',
        bg: 'var(--bm-error-dim)',
        fg: 'var(--bm-error)',
    },
    rejected: {
        label: 'Rejected',
        bg: 'var(--bm-error-dim)',
        fg: 'var(--bm-error)',
    },
    complained: {
        label: 'Complained',
        bg: 'var(--bm-error-dim)',
        fg: 'var(--bm-error)',
    },
    unsubscribed: {
        label: 'Unsubscribed',
        bg: 'var(--bm-error-dim)',
        fg: 'var(--bm-error)',
    },
    failed: {
        label: 'Failed',
        bg: 'var(--bm-error-dim)',
        fg: 'var(--bm-error)',
    },
    skipped: {
        label: 'Skipped',
        bg: 'var(--bm-elevated)',
        fg: 'var(--bm-text-3)',
    },
};

const statusMetaFor = (status: SendStatus): StatusMeta =>
    STATUS_META[status] ?? STATUS_META.pending;

interface StatTotals {
    contacts: number;
    unsubscribed: number;
    clicked: number;
}

const props = defineProps<{
    sequence: Sequence;
    step: SequenceStep;
    stepIndex?: number | null;
    contacts: PaginatedContacts & { data: SequenceContact[] };
    filter: ContactFilter;
    stats: StatTotals;
}>();

const stepTitle = computed(() =>
    props.step.message?.subject?.trim()
        ? props.step.message.subject
        : 'Untitled step',
);

const stepLabel = computed(() => {
    if (props.stepIndex) {
        return `Step ${props.stepIndex}`;
    }
    return 'Step';
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sequences', href: sequencesIndex().url },
    { title: props.sequence.name, href: sequenceShow(props.sequence.id).url },
    {
        title: `${stepLabel.value}`,
        href: stepsRoutes.show([props.sequence.id, props.step.id]).url,
    },
    {
        title: 'Contacts',
        href: stepContacts.index([props.sequence.id, props.step.id]).url,
    },
];

const filterOptions: FilterOption[] = [
    {
        key: 'all',
        label: 'All contacts',
        description: 'Contacts who reached this step',
    },
    {
        key: 'unsubscribed',
        label: 'Unsubscribed',
        description: 'Opted out after this email',
    },
    {
        key: 'clicked',
        label: 'Clicked',
        description: 'Engaged with a link in this step',
    },
];

const filterLink = (filter: string): string => {
    const args: [number, number] = [props.sequence.id, props.step.id];

    if (filter === 'all') {
        return stepContacts.index(args).url;
    }

    return stepContacts.index(args, {
        query: { filter },
    }).url;
};

const statCards = computed(() => [
    {
        key: 'contacts',
        label: 'Contacts in this step',
        description: 'Emailed or scheduled',
        value: props.stats.contacts,
        href: filterLink('all'),
    },
    {
        key: 'unsubscribed',
        label: 'Unsubscribed here',
        description: 'Stopped after this step',
        value: props.stats.unsubscribed,
        href: filterLink('unsubscribed'),
    },
    {
        key: 'clicked',
        label: 'Clicked a link',
        description: 'Engaged at this step',
        value: props.stats.clicked,
        href: filterLink('clicked'),
    },
]);

const showClickColumn = computed(() => props.filter === 'clicked');

const exportUrl = computed(
    () =>
        exportContactMails([props.sequence.id, props.step.id], {
            query: { filter: props.filter },
        }).url,
);

const page = usePage();
const userTimezone = computed(() => page.props.auth.timezone ?? 'UTC');

const formatDateTime = (value?: string | null): string =>
    value
        ? new Date(value).toLocaleString(undefined, {
              timeZone: userTimezone.value,
          })
        : '—';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${sequence.name} · ${stepLabel} contacts`" />

        <PaginatedContactsTable
            :contacts="contacts"
            :filter="filter"
            :filter-options="filterOptions"
            :stat-cards="statCards"
            :filter-link="filterLink"
            hide-added
        >
            <template #header>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p
                            class="text-[10px] font-semibold tracking-widest uppercase"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-3);
                            "
                        >
                            {{ stepLabel }}
                        </p>
                        <h1
                            class="mt-1 text-2xl font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            {{ stepTitle }}
                        </h1>
                        <p class="mt-1 text-sm" style="color: var(--bm-text-3)">
                            Review contacts currently at this step. The CSV
                            includes all historical emails matching the selected
                            filter, including contacts who advanced or finished.
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="exportUrl" download>
                            <PhDownloadSimple :size="14" class="mr-1.5" />
                            Export history CSV
                        </a>
                    </Button>
                </div>
            </template>

            <template #extra-headers>
                <th
                    v-if="showClickColumn"
                    class="px-4 py-2.5 text-left text-[10px] font-semibold tracking-widest uppercase"
                    style="color: var(--bm-text-3)"
                >
                    Link
                </th>
                <th
                    class="px-4 py-2.5 text-left text-[10px] font-semibold tracking-widest uppercase"
                    style="color: var(--bm-text-3)"
                >
                    Status
                </th>
                <th
                    class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                    style="color: var(--bm-text-3)"
                >
                    Send date
                </th>
            </template>

            <template #extra-columns="{ contact }">
                <td v-if="showClickColumn" class="px-4 py-3">
                    <div class="max-w-[220px] truncate text-sm">
                        <a
                            v-if="
                                (contact as SequenceContact).latest_click?.url
                            "
                            :href="
                                (contact as SequenceContact).latest_click!.url!
                            "
                            class="transition hover:underline"
                            style="color: var(--bm-accent)"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {{ (contact as SequenceContact).latest_click!.url }}
                        </a>
                        <span v-else style="color: var(--bm-text-3)">—</span>
                    </div>
                    <p
                        v-if="
                            (contact as SequenceContact).latest_click
                                ?.clicked_at
                        "
                        class="mt-1 text-xs tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            color: var(--bm-text-4);
                        "
                    >
                        {{
                            formatDateTime(
                                (contact as SequenceContact).latest_click
                                    ?.clicked_at,
                            )
                        }}
                    </p>
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="inline-flex items-center gap-1.5 px-2 py-0.5"
                            :style="{
                                backgroundColor: statusMetaFor(
                                    (contact as SequenceContact).send_status,
                                ).bg,
                                color: statusMetaFor(
                                    (contact as SequenceContact).send_status,
                                ).fg,
                                fontFamily: 'var(--bm-font-mono)',
                                fontSize: '10px',
                                fontWeight: 500,
                                borderRadius: '3px',
                            }"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :style="{
                                    backgroundColor: statusMetaFor(
                                        (contact as SequenceContact)
                                            .send_status,
                                    ).fg,
                                }"
                            ></span>
                            {{
                                statusMetaFor(
                                    (contact as SequenceContact).send_status,
                                ).label
                            }}
                        </span>
                        <span
                            v-if="
                                (contact as SequenceContact).send_status !==
                                    'unsubscribed' &&
                                (contact as SequenceContact)
                                    .step_unsubscribed_at
                            "
                            class="inline-flex items-center gap-1.5 px-2 py-0.5"
                            :style="{
                                backgroundColor: 'var(--bm-error-dim)',
                                color: 'var(--bm-error)',
                                fontFamily: 'var(--bm-font-mono)',
                                fontSize: '10px',
                                fontWeight: 500,
                                borderRadius: '3px',
                            }"
                        >
                            Unsub
                        </span>
                    </div>
                    <p
                        v-if="(contact as SequenceContact).step_failure_reason"
                        class="mt-1 max-w-[220px] truncate text-xs"
                        style="
                            font-family: var(--bm-font-mono);
                            color: var(--bm-text-3);
                        "
                        :title="
                            (contact as SequenceContact).step_failure_reason ??
                            undefined
                        "
                    >
                        {{ (contact as SequenceContact).step_failure_reason }}
                    </p>
                </td>
                <td
                    class="px-4 py-3 text-right tabular-nums"
                    style="
                        font-family: var(--bm-font-mono);
                        color: var(--bm-text-3);
                    "
                >
                    {{ formatDateTime((contact as SequenceContact).send_date) }}
                </td>
            </template>
        </PaginatedContactsTable>
    </AppLayout>
</template>
