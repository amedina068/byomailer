<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { show as contactShow } from '@/routes/contacts';

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedContacts {
    data: ContactRow[];
    links: PaginationLink[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    last_page: number;
    from: number | null;
    to: number | null;
}

export interface ContactRow {
    id: number;
    name: string | null;
    email: string;
    unsubscribed_at?: string | null;
    created_at: string;
}

export interface FilterOption {
    key: string;
    label: string;
    description: string;
}

export interface StatCard {
    key: string;
    label: string;
    description: string;
    value: number;
    href: string;
}

const props = withDefaults(
    defineProps<{
        contacts: PaginatedContacts;
        filter?: string;
        filterOptions?: FilterOption[];
        statCards?: StatCard[];
        filterLink?: (filter: string) => string;
        showStatus?: boolean;
        hideAdded?: boolean;
    }>(),
    {
        filter: 'all',
        filterOptions: () => [],
        statCards: () => [],
        filterLink: () => '#',
    },
);

const hasContacts = computed(() => props.contacts.data.length > 0);
const hasStatCards = computed(() => props.statCards.length > 0);
const hasFilterOptions = computed(() => props.filterOptions.length > 0);

const activeFilterDescription = computed(
    () =>
        props.filterOptions.find((option) => option.key === props.filter)
            ?.description ?? '',
);

const formatNumber = (value: number): string => value.toLocaleString();
const formatDate = (value?: string | null): string =>
    value ? new Date(value).toLocaleDateString() : '—';

const navigateToContact = (id: number): void => {
    router.visit(contactShow(id).url);
};

const onRowClick = (event: MouseEvent, id: number): void => {
    const target = event.target as HTMLElement | null;
    if (target?.closest('a, button')) {
        return;
    }
    navigateToContact(id);
};
</script>

<template>
    <div class="space-y-5">
        <!-- Header + stats -->
        <div v-if="hasStatCards || $slots.header" class="space-y-4">
            <slot name="header" />

            <div v-if="hasStatCards" class="grid gap-3 sm:grid-cols-3">
                <div
                    v-for="card in statCards"
                    :key="card.key"
                    class="rounded-[var(--bm-radius)] border p-4"
                    style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
                >
                    <p
                        class="truncate text-[10px] font-semibold uppercase tracking-widest"
                        style="color: var(--bm-text-3);"
                    >
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-semibold tabular-nums"
                        style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                    >
                        {{ formatNumber(card.value) }}
                    </p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <span class="text-xs" style="color: var(--bm-text-3);">
                            {{ card.description }}
                        </span>
                        <Link
                            :href="card.href"
                            preserve-state
                            preserve-scroll
                            class="text-xs font-medium transition hover:underline"
                            style="color: var(--bm-accent);"
                        >
                            View more
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table card -->
        <div
            class="rounded-[var(--bm-radius-md)] border"
            style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
        >
            <div
                v-if="hasFilterOptions"
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3"
                style="border-color: var(--bm-border);"
            >
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                        Contacts
                    </h2>
                    <span
                        class="px-1.5 py-0.5 text-[10px] tabular-nums"
                        style="font-family: var(--bm-font-mono); background-color: var(--bm-base); border: 1px solid var(--bm-border); color: var(--bm-text-3); border-radius: 3px;"
                    >
                        {{ formatNumber(contacts.total) }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-1">
                    <Link
                        v-for="option in filterOptions"
                        :key="option.key"
                        :href="filterLink(option.key)"
                        preserve-state
                        preserve-scroll
                        class="px-2.5 py-1 text-xs font-medium transition"
                        :style="{
                            backgroundColor:
                                option.key === filter
                                    ? 'var(--bm-accent-bright-dim)'
                                    : 'transparent',
                            color:
                                option.key === filter
                                    ? 'var(--bm-accent)'
                                    : 'var(--bm-text-3)',
                            borderRadius: 'var(--bm-radius)',
                        }"
                    >
                        {{ option.label }}
                    </Link>
                </div>
            </div>

            <p
                v-if="hasFilterOptions && activeFilterDescription"
                class="border-b px-5 py-2 text-xs"
                style="border-color: var(--bm-border); color: var(--bm-text-3);"
            >
                {{ activeFilterDescription }}
            </p>

            <div
                v-if="!hasContacts"
                class="px-5 py-10 text-center text-sm"
                style="color: var(--bm-text-3);"
            >
                No contacts match this filter yet.
            </div>

            <table v-else class="min-w-full text-sm">
                <thead>
                    <tr
                        style="background-color: var(--bm-surface-2); border-bottom: 1px solid var(--bm-border-strong);"
                    >
                        <th
                            class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            Name
                        </th>
                        <th
                            class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            Email
                        </th>
                        <slot name="extra-headers" />
                        <th
                            v-if="showStatus"
                            class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            Status
                        </th>
                        <th
                            v-if="!hideAdded"
                            class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            Added
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        class="cursor-pointer transition-colors hover:bg-[var(--bm-surface-2)] focus-visible:outline-none"
                        style="border-bottom: 1px solid var(--bm-border);"
                        role="link"
                        tabindex="0"
                        @click="onRowClick($event, contact.id)"
                        @keydown.enter.prevent="navigateToContact(contact.id)"
                        @keydown.space.prevent="navigateToContact(contact.id)"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="contactShow(contact.id).url"
                                class="text-[13px] font-medium transition hover:underline"
                                style="color: var(--bm-text-1);"
                            >
                                {{ contact.name || '—' }}
                            </Link>
                        </td>
                        <td class="px-4 py-3" style="color: var(--bm-text-3);">
                            {{ contact.email }}
                        </td>
                        <slot name="extra-columns" :contact="contact" />
                        <td v-if="showStatus" class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1.5 px-2 py-0.5"
                                :style="{
                                    backgroundColor: contact.unsubscribed_at
                                        ? 'var(--bm-error-dim)'
                                        : 'var(--bm-success-dim)',
                                    color: contact.unsubscribed_at
                                        ? 'var(--bm-error)'
                                        : 'var(--bm-success)',
                                    fontFamily: 'var(--bm-font-mono)',
                                    fontSize: '10px',
                                    fontWeight: 500,
                                    borderRadius: '3px',
                                }"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :style="{
                                        backgroundColor: contact.unsubscribed_at
                                            ? 'var(--bm-error)'
                                            : 'var(--bm-success)',
                                    }"
                                ></span>
                                {{ contact.unsubscribed_at ? 'Unsubscribed' : 'Active' }}
                            </span>
                        </td>
                        <td
                            v-if="!hideAdded"
                            class="px-4 py-3 text-right tabular-nums"
                            style="font-family: var(--bm-font-mono); color: var(--bm-text-3);"
                        >
                            {{ formatDate(contact.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div
                v-if="contacts.last_page > 1"
                class="flex items-center justify-between gap-4 border-t px-5 py-3"
                style="border-color: var(--bm-border);"
            >
                <p
                    class="text-xs tabular-nums"
                    style="font-family: var(--bm-font-mono); color: var(--bm-text-3);"
                >
                    {{ contacts.from }}–{{ contacts.to }} of {{ formatNumber(contacts.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!contacts.prev_page_url"
                        @click="
                            contacts.prev_page_url &&
                                router.visit(contacts.prev_page_url, {
                                    preserveState: true,
                                    preserveScroll: true,
                                })
                        "
                    >
                        <PhCaretLeft :size="14" />
                        <span class="sr-only">Previous</span>
                    </Button>
                    <template
                        v-for="(link, index) in contacts.links"
                        :key="`${link.label}-${index}`"
                    >
                        <Button
                            v-if="
                                !link.label.includes('Previous') &&
                                !link.label.includes('Next')
                            "
                            variant="outline"
                            size="sm"
                            class="hidden tabular-nums sm:inline-flex"
                            :style="
                                link.active
                                    ? {
                                          backgroundColor: 'var(--bm-accent)',
                                          color: 'var(--bm-accent-text)',
                                          borderColor: 'var(--bm-accent)',
                                      }
                                    : {}
                            "
                            :disabled="!link.url"
                            @click="
                                link.url &&
                                    router.visit(link.url, {
                                        preserveState: true,
                                        preserveScroll: true,
                                    })
                            "
                        >
                            {{ link.label }}
                        </Button>
                    </template>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!contacts.next_page_url"
                        @click="
                            contacts.next_page_url &&
                                router.visit(contacts.next_page_url, {
                                    preserveState: true,
                                    preserveScroll: true,
                                })
                        "
                    >
                        <PhCaretRight :size="14" />
                        <span class="sr-only">Next</span>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
