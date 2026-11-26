<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    PhBookOpen,
    PhCheckCircle,
    PhCurrencyDollar,
    PhCursorClick,
    PhLightning,
    PhListDashes,
    PhPaperPlaneTilt,
    PhUploadSimple,
    PhUserMinus,
    PhWarning,
} from '@phosphor-icons/vue';
import { computed } from 'vue';

import ContactTable from '@/components/contacts/ContactTable.vue';
import ContactGrowthChart from '@/components/dashboard/ContactGrowthChart.vue';
import SesSetupSteps from '@/components/dashboard/SesSetupSteps.vue';
import NewsletterRow from '@/components/newsletters/NewsletterRow.vue';
import SequenceCard from '@/components/sequences/SequenceCard.vue';
import SesPermissionBanner from '@/components/SesPermissionBanner.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { create as automationsCreate } from '@/routes/automations';
import {
    importMethod as contactsImport,
    index as contactsIndex,
} from '@/routes/contacts';
import { create as listsCreate } from '@/routes/lists';
import {
    create as newslettersCreate,
    show as newsletterShow,
    index as newslettersIndex,
} from '@/routes/newsletters';
import {
    create as sequencesCreate,
    show as sequenceShow,
    index as sequencesIndex,
} from '@/routes/sequences';
import { type BreadcrumbItem } from '@/types';
import type { NewsletterStatusOption } from '@/types/newsletters';
import type { SequenceStatusOption } from '@/types/sequences';

interface NewsletterSummary {
    id: number;
    subject: string;
    status: string;
    sent_at?: string | null;
    created_at: string;
    metrics: {
        total: number;
        sent: number;
        pending: number;
        opened: number;
        clicked: number;
        bounced: number;
        unsubscribed: number;
        complained: number;
        open_rate: number | null;
        click_rate: number | null;
        unsubscribe_rate: number | null;
    };
}

interface ContactSummary {
    id: number;
    name?: string | null;
    email: string;
    created_at: string;
}

interface SequenceSummary {
    id: number;
    name: string;
    status: string;
    steps_count: number;
    active_count: number;
    completed_count: number;
    created_at?: string | null;
}

interface WeeklyStats {
    sent: number;
    delivered: number;
    unsubscribed: number;
    clicked: number;
    bounced: number;
}

interface SesQuota {
    max_24_hour_send?: number | null;
    max_send_rate?: number | null;
    sent_last_24_hours?: number | null;
}

interface MonthlySesCost {
    emails_sent: number;
    estimated_cost: number;
}

interface ContactGrowthPoint {
    day: string;
    new: number;
    unsubscribed: number;
    net: number;
}

const props = defineProps<{
    hasCredentials: boolean;
    hasVerifiedIdentity: boolean;
    pendingIdentityId: number | null;
    missingPermissions: string[];
    sesSetup: { sandbox: boolean | null; eventsConfigured: boolean | null };
    monthlySesCost: MonthlySesCost;
    weeklyStats: WeeklyStats;
    contactGrowth?: ContactGrowthPoint[];
    sesQuota?: SesQuota | null;
    latestNewsletters?: NewsletterSummary[];
    newsletterStatuses: NewsletterStatusOption[];
    recentSequences?: SequenceSummary[];
    sequenceStatuses: SequenceStatusOption[];
    recentContacts?: ContactSummary[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

const deferredLoaded = computed(
    () =>
        props.latestNewsletters !== undefined &&
        props.recentContacts !== undefined,
);
const hasNewsletters = computed(
    () =>
        props.latestNewsletters !== undefined &&
        props.latestNewsletters.length > 0,
);
const hasSequences = computed(
    () =>
        props.recentSequences !== undefined && props.recentSequences.length > 0,
);
const hasContacts = computed(
    () => props.recentContacts !== undefined && props.recentContacts.length > 0,
);

const isFullyOnboarded = computed(
    () => props.hasCredentials && props.hasVerifiedIdentity,
);

const showGettingStarted = computed(
    () =>
        deferredLoaded.value &&
        isFullyOnboarded.value &&
        !hasNewsletters.value &&
        !hasContacts.value,
);

const formatNumber = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return '0';
    }

    return value.toLocaleString();
};
const statCards = computed(() => [
    {
        label: 'Mails sent',
        value: props.weeklyStats.sent,
        icon: PhPaperPlaneTilt,
        color: '--bm-info',
        bg: 'var(--bm-info-dim)',
    },
    {
        label: 'Delivered',
        value: props.weeklyStats.delivered,
        icon: PhCheckCircle,
        color: '--bm-success',
        bg: 'var(--bm-success-dim)',
    },
    {
        label: 'Unsubscribes',
        value: props.weeklyStats.unsubscribed,
        icon: PhUserMinus,
        color: '--bm-error',
        bg: 'var(--bm-error-dim)',
    },
    {
        label: 'Clicks',
        value: props.weeklyStats.clicked,
        icon: PhCursorClick,
        color: '--bm-accent',
        bg: 'var(--bm-accent-dim)',
    },
    {
        label: 'Bounced',
        value: props.weeklyStats.bounced,
        icon: PhWarning,
        color: '--bm-warning',
        bg: 'var(--bm-warning-dim)',
    },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <section class="space-y-6">
            <!-- First run: the SES steps come first. -->
            <SesSetupSteps
                :has-credentials="hasCredentials"
                :has-verified-identity="hasVerifiedIdentity"
                :pending-identity-id="pendingIdentityId"
                :sandbox="sesSetup.sandbox"
                :events-configured="sesSetup.eventsConfigured"
            />

            <SesPermissionBanner
                :missing-permissions="missingPermissions"
                message="Your AWS credentials are missing required SES permissions. Newsletters and sequences may fail to send."
            />

            <!-- Getting Started Tasks -->
            <template v-if="showGettingStarted">
                <div class="mb-2">
                    <h2
                        class="text-xl font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Get started
                    </h2>
                    <p class="text-sm" style="color: var(--bm-text-2)">
                        Complete these steps to start sending your first
                        campaign.
                    </p>
                </div>

                <!-- Create a list -->
                <div
                    class="flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center"
                    style="
                        background-color: var(--bm-surface);
                        border: 1px solid var(--bm-border);
                        border-radius: var(--bm-radius-md);
                        box-shadow: var(--bm-shadow-xs);
                    "
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center"
                        style="
                            background-color: var(--bm-accent-dim);
                            border-radius: var(--bm-radius);
                        "
                    >
                        <PhListDashes
                            :size="24"
                            style="color: var(--bm-accent)"
                        />
                    </div>
                    <div class="min-w-0 grow">
                        <h3
                            class="font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Create a list
                        </h3>
                        <p class="text-sm" style="color: var(--bm-text-2)">
                            Organize your contacts into lists to target specific
                            audiences.
                        </p>
                    </div>
                    <Button as-child size="sm" class="shrink-0">
                        <Link :href="listsCreate().url">Create List</Link>
                    </Button>
                </div>

                <!-- Add contacts -->
                <div
                    class="flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center"
                    style="
                        background-color: var(--bm-surface);
                        border: 1px solid var(--bm-border);
                        border-radius: var(--bm-radius-md);
                        box-shadow: var(--bm-shadow-xs);
                    "
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center"
                        style="
                            background-color: var(--bm-accent-dim);
                            border-radius: var(--bm-radius);
                        "
                    >
                        <PhUploadSimple
                            :size="24"
                            style="color: var(--bm-accent)"
                        />
                    </div>
                    <div class="min-w-0 grow">
                        <h3
                            class="font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Add contacts
                        </h3>
                        <p class="text-sm" style="color: var(--bm-text-2)">
                            Import your existing contacts or add them via our
                            API.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Button as-child size="sm">
                            <Link :href="contactsImport().url">Import CSV</Link>
                        </Button>
                        <Button as-child variant="outline" size="sm">
                            <a
                                href="/documentation"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Read Documentation <PhBookOpen :size="16" />
                            </a>
                        </Button>
                    </div>
                </div>

                <!-- Create automation -->
                <div
                    class="flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center"
                    style="
                        background-color: var(--bm-surface);
                        border: 1px solid var(--bm-border);
                        border-radius: var(--bm-radius-md);
                        box-shadow: var(--bm-shadow-xs);
                    "
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center"
                        style="
                            background-color: var(--bm-accent-dim);
                            border-radius: var(--bm-radius);
                        "
                    >
                        <PhLightning
                            :size="24"
                            style="color: var(--bm-accent)"
                        />
                    </div>
                    <div class="min-w-0 grow">
                        <h3
                            class="font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Start automating
                        </h3>
                        <p class="text-sm" style="color: var(--bm-text-2)">
                            Set up automated email sequences triggered by
                            events.
                        </p>
                    </div>
                    <Button as-child size="sm" class="shrink-0">
                        <Link :href="automationsCreate().url"
                            >Create Automation</Link
                        >
                    </Button>
                </div>
            </template>

            <!-- Dashboard Content -->
            <div class="grid gap-6">
                <div class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2">
                    <!-- SES Quota -->
                    <div
                        v-if="sesQuota"
                        class="p-5"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center"
                                style="
                                    background-color: var(--bm-info-dim);
                                    border-radius: var(--bm-radius);
                                "
                            >
                                <PhPaperPlaneTilt
                                    :size="20"
                                    style="color: var(--bm-info)"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    Sending quota
                                </h2>
                                <p
                                    class="text-xs"
                                    style="color: var(--bm-text-3)"
                                >
                                    Current SES account limits
                                </p>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    24h sent
                                </p>
                                <p
                                    class="text-lg font-semibold"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-1);
                                    "
                                >
                                    {{
                                        formatNumber(
                                            props.sesQuota?.sent_last_24_hours,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    Daily limit
                                </p>
                                <p
                                    class="text-lg font-semibold"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-1);
                                    "
                                >
                                    {{
                                        formatNumber(
                                            props.sesQuota?.max_24_hour_send,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    Rate limit
                                </p>
                                <p
                                    class="text-lg font-semibold"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-1);
                                    "
                                >
                                    {{
                                        formatNumber(
                                            props.sesQuota?.max_send_rate,
                                        )
                                    }}
                                    <span
                                        class="text-xs font-normal"
                                        style="color: var(--bm-text-3)"
                                        >/sec</span
                                    >
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly SES Costs -->
                    <div
                        class="p-5"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center"
                                style="
                                    background-color: var(--bm-accent-dim);
                                    border-radius: var(--bm-radius);
                                "
                            >
                                <PhCurrencyDollar
                                    :size="20"
                                    style="color: var(--bm-accent)"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    Month SES costs
                                </h2>
                                <p
                                    class="text-xs"
                                    style="color: var(--bm-text-3)"
                                >
                                    Estimated at the SES à la carte rate of
                                    $0.10 per 1,000 emails
                                </p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    Emails sent
                                </p>
                                <p
                                    class="text-2xl font-bold tabular-nums"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-1);
                                    "
                                >
                                    {{
                                        formatNumber(
                                            props.monthlySesCost.emails_sent,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    Estimated cost
                                </p>
                                <p
                                    class="text-2xl font-bold tabular-nums"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-accent);
                                    "
                                >
                                    ${{
                                        props.monthlySesCost.estimated_cost.toFixed(
                                            2,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Weekly Stats -->
                <div class="grid min-w-0 grid-cols-2 gap-4 lg:grid-cols-5">
                    <div
                        v-for="stat in statCards"
                        :key="stat.label"
                        class="p-5"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center"
                                :style="{
                                    backgroundColor: stat.bg,
                                    borderRadius: 'var(--bm-radius)',
                                }"
                            >
                                <component
                                    :is="stat.icon"
                                    :size="20"
                                    :style="{ color: `var(${stat.color})` }"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-widest uppercase"
                                    style="color: var(--bm-text-3)"
                                >
                                    {{ stat.label }}
                                </p>
                                <p
                                    class="text-2xl font-bold tabular-nums"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-1);
                                    "
                                >
                                    {{ stat.value }}
                                </p>
                            </div>
                        </div>
                        <p class="mt-2 text-xs" style="color: var(--bm-text-3)">
                            Last 7 days
                        </p>
                    </div>
                </div>

                <Deferred data="contactGrowth">
                    <template #fallback>
                        <div
                            class="h-64 animate-pulse"
                            style="
                                background-color: var(--bm-surface);
                                border: 1px solid var(--bm-border);
                                border-radius: var(--bm-radius-md);
                            "
                        />
                    </template>
                    <ContactGrowthChart :data="contactGrowth ?? []" />
                </Deferred>

                <Deferred data="latestNewsletters">
                    <template #fallback>
                        <div class="space-y-4">
                            <div
                                v-for="n in 3"
                                :key="n"
                                class="h-20 animate-pulse"
                                style="
                                    background-color: var(--bm-surface);
                                    border: 1px solid var(--bm-border);
                                    border-radius: var(--bm-radius-md);
                                "
                            />
                        </div>
                    </template>
                    <div
                        class="min-w-0 p-6"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-4"
                        >
                            <div>
                                <h2
                                    class="text-xl font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    Latest newsletters
                                </h2>
                                <p
                                    class="text-sm"
                                    style="color: var(--bm-text-2)"
                                >
                                    Monitor delivery for your five most recent
                                    sends.
                                </p>
                            </div>
                            <Link
                                :href="newslettersIndex().url"
                                class="text-sm font-medium transition hover:underline"
                                style="color: var(--bm-accent)"
                            >
                                View all
                            </Link>
                        </div>

                        <div class="mt-6">
                            <div v-if="hasNewsletters" class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr
                                            style="
                                                background-color: var(
                                                    --bm-surface-2
                                                );
                                                border-bottom: 1px solid
                                                    var(--bm-border-strong);
                                            "
                                        >
                                            <th
                                                class="px-4 py-2.5 text-left text-[10px] font-semibold tracking-widest uppercase"
                                                style="color: var(--bm-text-3)"
                                            >
                                                Newsletter
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
                                                Recipients
                                            </th>
                                            <th
                                                class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                                                style="color: var(--bm-text-3)"
                                            >
                                                Sent
                                            </th>
                                            <th
                                                class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                                                style="color: var(--bm-text-3)"
                                            >
                                                Open
                                            </th>
                                            <th
                                                class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                                                style="color: var(--bm-text-3)"
                                            >
                                                Click
                                            </th>
                                            <th
                                                class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                                                style="color: var(--bm-text-3)"
                                            >
                                                Unsub
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <NewsletterRow
                                            v-for="newsletter in props.latestNewsletters"
                                            :key="newsletter.id"
                                            :newsletter="newsletter"
                                            :statuses="props.newsletterStatuses"
                                            :view-url="
                                                newsletterShow(newsletter.id)
                                                    .url
                                            "
                                        />
                                    </tbody>
                                </table>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center py-8 text-center"
                            >
                                <div
                                    class="mb-3 flex h-12 w-12 items-center justify-center"
                                    style="
                                        background-color: var(--bm-elevated);
                                        border-radius: var(--bm-radius);
                                    "
                                >
                                    <PhPaperPlaneTilt
                                        :size="24"
                                        style="color: var(--bm-text-3)"
                                    />
                                </div>
                                <p
                                    class="mb-4 text-sm"
                                    style="color: var(--bm-text-3)"
                                >
                                    No newsletters yet. Create your first
                                    campaign to start sending.
                                </p>
                                <Button as-child size="sm">
                                    <Link :href="newslettersCreate().url"
                                        >Create Newsletter</Link
                                    >
                                </Button>
                            </div>
                        </div>
                    </div>
                </Deferred>

                <Deferred :data="['recentSequences', 'recentContacts']">
                    <template #fallback>
                        <div class="space-y-4">
                            <div
                                v-for="n in 2"
                                :key="n"
                                class="h-48 animate-pulse"
                                style="
                                    background-color: var(--bm-surface);
                                    border: 1px solid var(--bm-border);
                                    border-radius: var(--bm-radius-md);
                                "
                            />
                        </div>
                    </template>
                    <div
                        class="min-w-0 p-6"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-4"
                        >
                            <div>
                                <h2
                                    class="text-xl font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    Latest contacts
                                </h2>
                                <p
                                    class="text-sm"
                                    style="color: var(--bm-text-2)"
                                >
                                    Recent subscribers and their subscription
                                    status.
                                </p>
                            </div>
                            <Link
                                :href="contactsIndex().url"
                                class="text-sm font-medium transition hover:underline"
                                style="color: var(--bm-accent)"
                            >
                                View all
                            </Link>
                        </div>

                        <div class="mt-6">
                            <ContactTable
                                v-if="hasContacts"
                                :contacts="props.recentContacts ?? []"
                            />

                            <div
                                v-else
                                class="flex flex-col items-center py-8 text-center"
                            >
                                <div
                                    class="mb-3 flex h-12 w-12 items-center justify-center"
                                    style="
                                        background-color: var(--bm-elevated);
                                        border-radius: var(--bm-radius);
                                    "
                                >
                                    <PhUploadSimple
                                        :size="24"
                                        style="color: var(--bm-text-3)"
                                    />
                                </div>
                                <p
                                    class="mb-4 text-sm"
                                    style="color: var(--bm-text-3)"
                                >
                                    No contacts added yet. Import your contacts
                                    to get started.
                                </p>
                                <div class="flex gap-2">
                                    <Button as-child size="sm">
                                        <Link :href="contactsImport().url"
                                            >Import CSV</Link
                                        >
                                    </Button>
                                    <Button
                                        as-child
                                        variant="outline"
                                        size="sm"
                                    >
                                        <a
                                            href="/documentation"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Read Documentation
                                            <PhBookOpen :size="16" />
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="min-w-0 p-6"
                        style="
                            background-color: var(--bm-surface);
                            border: 1px solid var(--bm-border);
                            border-radius: var(--bm-radius-md);
                            box-shadow: var(--bm-shadow-xs);
                        "
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-4"
                        >
                            <div>
                                <h2
                                    class="text-xl font-semibold"
                                    style="color: var(--bm-text-1)"
                                >
                                    Latest sequences
                                </h2>
                                <p
                                    class="text-sm"
                                    style="color: var(--bm-text-2)"
                                >
                                    Track contacts flowing through your five
                                    most recent live sequences.
                                </p>
                            </div>
                            <Link
                                :href="sequencesIndex().url"
                                class="text-sm font-medium transition hover:underline"
                                style="color: var(--bm-accent)"
                            >
                                View all
                            </Link>
                        </div>

                        <div class="mt-6 space-y-4">
                            <SequenceCard
                                v-for="sequence in props.recentSequences"
                                :key="sequence.id"
                                :sequence="sequence"
                                :statuses="props.sequenceStatuses"
                                :view-url="sequenceShow(sequence.id).url"
                            />
                            <div
                                v-if="!hasSequences"
                                class="flex flex-col items-center py-8 text-center"
                            >
                                <div
                                    class="mb-3 flex h-12 w-12 items-center justify-center"
                                    style="
                                        background-color: var(--bm-elevated);
                                        border-radius: var(--bm-radius);
                                    "
                                >
                                    <PhLightning
                                        :size="24"
                                        style="color: var(--bm-text-3)"
                                    />
                                </div>
                                <p
                                    class="mb-4 text-sm"
                                    style="color: var(--bm-text-3)"
                                >
                                    No sequences yet. Kick off an automated
                                    journey to see engagement here.
                                </p>
                                <Button as-child size="sm">
                                    <Link :href="sequencesCreate().url"
                                        >Create Sequence</Link
                                    >
                                </Button>
                            </div>
                        </div>
                    </div>
                </Deferred>
            </div>
        </section>
    </AppLayout>
</template>
