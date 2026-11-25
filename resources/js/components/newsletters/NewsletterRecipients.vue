<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    PhCaretLeft,
    PhCaretRight,
    PhDownloadSimple,
    PhUploadSimple,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import NewsletterMailExportController from '@/actions/App/Http/Controllers/NewsletterMailExportController';
import LinkClicksPanel from '@/components/links/LinkClicksPanel.vue';
import LinkFilterChip from '@/components/links/LinkFilterChip.vue';
import { Button } from '@/components/ui/button';
import type { LinkClickSummary } from '@/lib/linkClicks';
import { show as contactShow } from '@/routes/contacts';
import { show as newsletterShow } from '@/routes/newsletters';

export interface Recipient {
    id: number;
    name: string;
    email: string;
    contact_mail_status: string;
    link_clicks_count?: number | null;
    link_first_clicked_at?: string | null;
}

export interface PaginatedRecipients {
    data: Recipient[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    newsletterId: number;
    recipients: PaginatedRecipients;
    statusFilter: string;
    statusOptions: string[];
    linkFilter?: string | null;
    linkClicks?: LinkClickSummary;
    importUrl: string | null;
}>();

const selectedStatus = ref(props.statusFilter);
const recipientsSection = ref<HTMLElement | null>(null);

const statusFilters = computed(() => ['all', ...props.statusOptions]);

const exportUrl = computed(() =>
    NewsletterMailExportController.url(props.newsletterId, {
        query:
            selectedStatus.value === 'all'
                ? {}
                : { status: selectedStatus.value },
    }),
);

const visitRecipients = (
    link: string | null | undefined,
    onSuccess?: () => void,
): void => {
    const query = {
        ...(selectedStatus.value === 'all'
            ? {}
            : { status: selectedStatus.value }),
        ...(link ? { link } : {}),
    };

    router.visit(newsletterShow(props.newsletterId, { query }).url, {
        preserveState: true,
        preserveScroll: true,
        only: [
            'recipients',
            'recipientStatusFilter',
            'recipientStatusOptions',
            'recipientLinkFilter',
        ],
        onSuccess,
    });
};

const selectLink = (key: string): void => {
    const link = key === props.linkFilter ? null : key;

    visitRecipients(link, () => {
        if (link) {
            recipientsSection.value?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    });
};

const emptyMessage = computed((): string => {
    if (!props.linkFilter) {
        return 'No recipients yet.';
    }

    return props.statusFilter === 'all'
        ? 'No recipients clicked this link.'
        : 'No recipients with this status clicked this link.';
});

const formatStatus = (status: string): string =>
    status
        .split('_')
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');

const statusMeta = (status?: string | null): { fg: string; bg: string } => {
    const normalized = status?.toLowerCase() ?? '';

    if (normalized === 'opened' || normalized === 'clicked') {
        return { fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' };
    }

    if (normalized === 'delivered') {
        return { fg: 'var(--bm-info)', bg: 'var(--bm-info-dim)' };
    }

    if (normalized === 'sent') {
        return { fg: 'var(--bm-text-2)', bg: 'var(--bm-surface-2)' };
    }

    if (['bounced', 'complained', 'unsubscribed'].includes(normalized)) {
        return { fg: 'var(--bm-error)', bg: 'var(--bm-error-dim)' };
    }

    return { fg: 'var(--bm-text-3)', bg: 'var(--bm-surface-2)' };
};

const visitPage = (url: string | null): void => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};

const openContact = (id: number): void => {
    router.visit(contactShow(id).url);
};
</script>

<template>
    <div class="space-y-6">
        <LinkClicksPanel
            :link-clicks="linkClicks"
            :active-key="linkFilter"
            select-hint="Select a link to see who clicked it."
            @select="selectLink"
        />

        <div
            ref="recipientsSection"
            class="scroll-mt-4 overflow-hidden rounded-(--bm-radius-md) border border-(--bm-border) bg-(--bm-surface) shadow-(--bm-shadow-xs)"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-(--bm-border) px-5 py-3"
            >
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-(--bm-text-1)">
                        Recipients
                    </h2>
                    <span
                        class="rounded-[3px] border border-(--bm-border) bg-(--bm-base) px-1.5 py-0.5 font-(family-name:--bm-font-mono) text-[10px] text-(--bm-text-3) tabular-nums"
                    >
                        {{ recipients.total.toLocaleString() }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="flex items-center rounded-(--bm-radius) border border-(--bm-border) bg-(--bm-base)"
                    >
                        <label
                            for="recipient-status-filter"
                            class="pl-2.5 text-[10px] font-semibold tracking-widest text-(--bm-text-3) uppercase"
                        >
                            Status
                        </label>
                        <select
                            id="recipient-status-filter"
                            v-model="selectedStatus"
                            class="bg-transparent px-2 py-1.5 text-sm text-(--bm-text-1) focus-visible:outline-none"
                            @change="visitRecipients(linkFilter)"
                        >
                            <option
                                v-for="status in statusFilters"
                                :key="status"
                                :value="status"
                            >
                                {{
                                    status === 'all'
                                        ? 'All statuses'
                                        : formatStatus(status)
                                }}
                            </option>
                        </select>
                    </div>
                    <Button as-child variant="outline" size="sm">
                        <a :href="exportUrl">
                            <PhDownloadSimple :size="14" class="mr-1.5" />
                            Export CSV
                        </a>
                    </Button>
                    <Button
                        v-if="importUrl"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="importUrl">
                            <PhUploadSimple :size="14" class="mr-1.5" />
                            Import contacts
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="linkFilter"
                class="flex flex-wrap items-center gap-2 border-b border-(--bm-border) px-5 py-2.5"
            >
                <LinkFilterChip
                    :link-key="linkFilter"
                    @clear="visitRecipients(null)"
                />
            </div>

            <table class="min-w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-(--bm-border-strong) bg-(--bm-surface-2) text-[10px] font-semibold tracking-widest text-(--bm-text-3) uppercase"
                    >
                        <th class="px-5 py-2.5 text-left">Recipient</th>
                        <th v-if="linkFilter" class="px-5 py-2.5 text-right">
                            Clicks
                        </th>
                        <th class="px-5 py-2.5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="recipient in recipients.data"
                        :key="recipient.id"
                        class="cursor-pointer border-b border-(--bm-border) transition-colors hover:bg-(--bm-surface-2) focus-visible:outline-none"
                        role="button"
                        tabindex="0"
                        @click="openContact(recipient.id)"
                        @keydown.enter.prevent="openContact(recipient.id)"
                        @keydown.space.prevent="openContact(recipient.id)"
                    >
                        <td class="px-5 py-3">
                            <p
                                class="truncate text-[13px] font-medium text-(--bm-text-1)"
                            >
                                {{ recipient.email }}
                            </p>
                            <p
                                v-if="recipient.name"
                                class="truncate text-xs text-(--bm-text-3)"
                            >
                                {{ recipient.name }}
                            </p>
                        </td>
                        <td
                            v-if="linkFilter"
                            class="px-5 py-3 text-right font-(family-name:--bm-font-mono) text-(--bm-text-1) tabular-nums"
                            :title="
                                recipient.link_first_clicked_at
                                    ? `First click: ${new Date(recipient.link_first_clicked_at).toLocaleString()}`
                                    : undefined
                            "
                        >
                            {{
                                (
                                    recipient.link_clicks_count ?? 0
                                ).toLocaleString()
                            }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-[3px] px-2 py-0.5 font-(family-name:--bm-font-mono) text-[10px] font-medium"
                                :style="{
                                    backgroundColor: statusMeta(
                                        recipient.contact_mail_status,
                                    ).bg,
                                    color: statusMeta(
                                        recipient.contact_mail_status,
                                    ).fg,
                                }"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :style="{
                                        backgroundColor: statusMeta(
                                            recipient.contact_mail_status,
                                        ).fg,
                                    }"
                                ></span>
                                {{
                                    formatStatus(recipient.contact_mail_status)
                                }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!recipients.data.length">
                        <td
                            :colspan="linkFilter ? 3 : 2"
                            class="px-5 py-10 text-center text-sm text-(--bm-text-3)"
                        >
                            {{ emptyMessage }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="recipients.last_page > 1"
                class="flex items-center justify-between gap-4 border-t border-(--bm-border) px-5 py-3"
            >
                <p
                    class="font-(family-name:--bm-font-mono) text-xs text-(--bm-text-3) tabular-nums"
                >
                    {{ recipients.from }}–{{ recipients.to }} of
                    {{ recipients.total.toLocaleString() }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!recipients.prev_page_url"
                        @click="visitPage(recipients.prev_page_url)"
                    >
                        <PhCaretLeft :size="14" />
                        <span class="sr-only">Previous</span>
                    </Button>
                    <template
                        v-for="(link, index) in recipients.links"
                        :key="index"
                    >
                        <Button
                            v-if="
                                !link.label.includes('Previous') &&
                                !link.label.includes('Next')
                            "
                            variant="outline"
                            size="sm"
                            class="hidden tabular-nums sm:inline-flex"
                            :class="{
                                'border-(--bm-accent) bg-(--bm-accent) text-(--bm-accent-text)':
                                    link.active,
                            }"
                            :disabled="!link.url"
                            @click="visitPage(link.url)"
                        >
                            {{ link.label }}
                        </Button>
                    </template>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!recipients.next_page_url"
                        @click="visitPage(recipients.next_page_url)"
                    >
                        <PhCaretRight :size="14" />
                        <span class="sr-only">Next</span>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
