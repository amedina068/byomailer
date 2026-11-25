<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    PhArchive,
    PhArrowCounterClockwise,
    PhFunnel,
    PhPencilSimple,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    filterToSentence,
    type FilterSentenceContext,
} from '@/lib/filterSentence';
import { show as newsletterShow } from '@/routes/newsletters';
import {
    archive as segmentsArchive,
    edit as segmentsEdit,
    index as segmentsIndex,
    show as segmentsShow,
} from '@/routes/segments';
import { show as sequenceShow } from '@/routes/sequences';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterFilter } from '@/types/newsletters';

interface SegmentData {
    id: number;
    name: string;
    description: string | null;
    filters: NewsletterFilter[];
    archived_at?: string | null;
}

interface ContactRow {
    id: number;
    uuid: string;
    name: string | null;
    email: string;
    unsubscribed_at: string | null;
}

interface PaginatedContacts {
    data: ContactRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface UsedBy {
    newsletters: { id: number; title: string }[];
    sequences: { id: number; name: string }[];
}

interface MailList {
    id: number;
    name: string;
}

interface TopicOption {
    id: number;
    name: string;
    list_name: string | null;
}

const props = defineProps<{
    segment: SegmentData;
    contacts: PaginatedContacts;
    matchingCount: number;
    eligibleCount: number;
    usedBy: UsedBy;
    lists: MailList[];
    topics?: TopicOption[];
    filterNewsletters?: { id: number; title: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Segments',
        href: segmentsIndex().url,
    },
    {
        title: props.segment.name,
        href: segmentsShow(props.segment.id).url,
    },
];

const sentenceContext = computed(
    (): FilterSentenceContext => ({
        lists: props.lists.map((list) => ({ id: list.id, name: list.name })),
        topics: (props.topics ?? []).map((topic) => ({
            id: topic.id,
            name: topic.name,
            list_name: topic.list_name,
        })),
        newsletters: (props.filterNewsletters ?? []).map((newsletter) => ({
            id: newsletter.id,
            title: newsletter.title,
        })),
    }),
);

const filters = computed(() => props.segment.filters ?? []);
const hasMatches = computed(() => props.matchingCount > 0);

const isArchived = computed(() => props.segment.archived_at != null);

const showArchive = ref(false);
const archiving = ref(false);

const setArchived = (archived: boolean): void => {
    archiving.value = true;
    router.patch(
        segmentsArchive(props.segment.id).url,
        { archived },
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.value = false;
                showArchive.value = false;
            },
        },
    );
};

const goTo = (url: string | null): void => {
    if (url) {
        router.visit(url, { preserveScroll: true, preserveState: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="segment.name" />

        <section class="space-y-6">
            <!-- Header -->
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-semibold text-foreground">
                                {{ segment.name }}
                            </h1>
                            <span
                                v-if="isArchived"
                                class="rounded px-2 py-0.5 text-[11px] font-medium tracking-wide uppercase"
                                style="
                                    background-color: var(--bm-surface-2);
                                    color: var(--bm-text-3);
                                "
                            >
                                Archived
                            </span>
                        </div>
                        <p
                            v-if="segment.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ segment.description }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="segmentsEdit(segment.id).url">
                                <PhPencilSimple :size="15" class="mr-1.5" />
                                Edit
                            </Link>
                        </Button>
                        <Button
                            v-if="isArchived"
                            variant="outline"
                            size="sm"
                            :disabled="archiving"
                            @click="setArchived(false)"
                        >
                            <PhArrowCounterClockwise
                                :size="15"
                                class="mr-1.5"
                            />
                            Restore
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            @click="showArchive = true"
                        >
                            <PhArchive :size="15" class="mr-1.5" />
                            Archive
                        </Button>
                    </div>
                </div>

                <!-- Two labeled counts -->
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div
                        class="rounded-lg border px-4 py-3"
                        style="
                            background-color: var(--bm-base);
                            border-color: var(--bm-border);
                        "
                    >
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ matchingCount.toLocaleString() }}
                        </p>
                        <p class="text-xs" style="color: var(--bm-text-3)">
                            contacts match (incl. unsubscribed)
                        </p>
                    </div>
                    <div
                        class="rounded-lg border px-4 py-3"
                        style="
                            background-color: var(--bm-base);
                            border-color: var(--bm-border);
                        "
                    >
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ eligibleCount.toLocaleString() }}
                        </p>
                        <p class="text-xs" style="color: var(--bm-text-3)">
                            eligible recipients (subscribed)
                        </p>
                    </div>
                </div>

                <!-- Filter sentences -->
                <div class="mt-5 space-y-2">
                    <p
                        class="text-[10px] font-semibold tracking-widest uppercase"
                        style="color: var(--bm-text-3)"
                    >
                        Filters
                    </p>
                    <div
                        v-for="(filter, index) in filters"
                        :key="index"
                        class="rounded-lg border px-3 py-2 text-sm"
                        style="
                            background-color: var(--bm-base);
                            border-color: var(--bm-border);
                            color: var(--bm-text-2);
                        "
                    >
                        {{ filterToSentence(filter, index, sentenceContext) }}
                    </div>
                </div>
            </div>

            <!-- Used by -->
            <div
                v-if="usedBy.newsletters.length || usedBy.sequences.length"
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <h2 class="text-sm font-semibold text-foreground">Used by</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Link
                        v-for="newsletter in usedBy.newsletters"
                        :key="`n-${newsletter.id}`"
                        :href="newsletterShow(newsletter.id).url"
                        class="rounded-md border px-2.5 py-1 text-xs transition hover:underline"
                        style="
                            border-color: var(--bm-border);
                            color: var(--bm-text-2);
                        "
                    >
                        ✉ {{ newsletter.title || 'Untitled newsletter' }}
                    </Link>
                    <Link
                        v-for="sequence in usedBy.sequences"
                        :key="`s-${sequence.id}`"
                        :href="sequenceShow(sequence.id).url"
                        class="rounded-md border px-2.5 py-1 text-xs transition hover:underline"
                        style="
                            border-color: var(--bm-border);
                            color: var(--bm-text-2);
                        "
                    >
                        ↻ {{ sequence.name }}
                    </Link>
                </div>
            </div>

            <!-- Matching contacts -->
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card dark:border-sidebar-border"
            >
                <div class="px-6 pt-6">
                    <h2 class="text-sm font-semibold text-foreground">
                        Matching contacts
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Live preview of who is in this segment right now.
                    </p>
                </div>

                <div v-if="hasMatches" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead
                            class="text-left text-[11px] tracking-wide uppercase"
                            style="
                                background-color: var(--bm-surface-2);
                                color: var(--bm-text-3);
                            "
                        >
                            <tr>
                                <th scope="col" class="px-6 py-2.5 font-medium">
                                    Name
                                </th>
                                <th scope="col" class="px-6 py-2.5 font-medium">
                                    Email
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-2.5 text-right font-medium"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="contact in contacts.data"
                                :key="contact.id"
                                style="border-top: 1px solid var(--bm-border)"
                            >
                                <td
                                    class="px-6 py-3"
                                    style="color: var(--bm-text-1)"
                                >
                                    {{ contact.name || '—' }}
                                </td>
                                <td
                                    class="px-6 py-3"
                                    style="
                                        font-family: var(--bm-font-mono);
                                        color: var(--bm-text-2);
                                    "
                                >
                                    {{ contact.email }}
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 text-[10px] font-medium"
                                        :style="{
                                            borderRadius: '3px',
                                            fontFamily: 'var(--bm-font-mono)',
                                            backgroundColor:
                                                contact.unsubscribed_at
                                                    ? 'var(--bm-error-dim)'
                                                    : 'var(--bm-success-dim)',
                                            color: contact.unsubscribed_at
                                                ? 'var(--bm-error)'
                                                : 'var(--bm-success)',
                                        }"
                                    >
                                        {{
                                            contact.unsubscribed_at
                                                ? 'Unsubscribed'
                                                : 'Subscribed'
                                        }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        v-if="contacts.last_page > 1"
                        class="flex items-center justify-between gap-3 px-6 py-4"
                    >
                        <span class="text-xs" style="color: var(--bm-text-3)">
                            {{ contacts.from ?? 0 }}–{{ contacts.to ?? 0 }} of
                            {{ contacts.total.toLocaleString() }}
                        </span>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!contacts.prev_page_url"
                                @click="goTo(contacts.prev_page_url)"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!contacts.next_page_url"
                                @click="goTo(contacts.next_page_url)"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- 0-match empty state -->
                <div
                    v-else
                    class="m-6 flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center"
                    style="border-color: var(--bm-border)"
                >
                    <PhFunnel :size="28" style="color: var(--bm-text-4)" />
                    <p class="text-sm" style="color: var(--bm-text-2)">
                        No contacts match these filters yet.
                    </p>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="segmentsEdit(segment.id).url">
                            Adjust filters
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <Dialog :open="showArchive" @update:open="showArchive = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Archive “{{ segment.name }}”?</DialogTitle>
                    <DialogDescription>
                        It will be hidden from the segment picker so it can't be
                        used by new newsletters or sequences.
                        <template
                            v-if="
                                usedBy.newsletters.length > 0 ||
                                usedBy.sequences.length > 0
                            "
                        >
                            The {{ usedBy.newsletters.length }}
                            {{
                                usedBy.newsletters.length === 1
                                    ? 'newsletter'
                                    : 'newsletters'
                            }}
                            and {{ usedBy.sequences.length }}
                            {{
                                usedBy.sequences.length === 1
                                    ? 'sequence'
                                    : 'sequences'
                            }}
                            already referencing it keep their audience
                            unchanged.
                        </template>
                        You can restore it anytime.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button :disabled="archiving" @click="setArchived(true)">
                        {{ archiving ? 'Archiving…' : 'Archive segment' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
