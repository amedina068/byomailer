<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Download, Filter, ListPlus, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import AddToSequenceModal from '@/components/contacts/AddToSequenceModal.vue';
import ContactsPagination from '@/components/contacts/ContactsPagination.vue';
import ContactTable from '@/components/contacts/ContactTable.vue';
import CreateContactModal from '@/components/contacts/CreateContactModal.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    importMethod as contactsImport,
    index as contactsIndex,
} from '@/routes/contacts';
import type { BreadcrumbItem } from '@/types';

interface Contact {
    id: number;
    uuid: string;
    name: string;
    email: string;
    unsubscribed_at?: string | null;
    unsubscribed_by_contact: boolean;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedContacts {
    data: Contact[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface MailList {
    id: number;
    name: string;
}

interface Tag {
    id: number;
    name: string;
}

interface Sequence {
    id: number;
    name: string;
    status: string;
}

interface Filters {
    lists: number[];
    tags: number[];
    include_unsubscribed: boolean;
    search: string;
}

const props = defineProps<{
    contacts: PaginatedContacts;
    lists: MailList[];
    tags: Tag[];
    sequences: Sequence[];
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Contacts',
        href: contactsIndex().url,
    },
];

const showCreateModal = ref(false);
const showAddToSequenceModal = ref(false);
const selectedContactIds = ref<number[]>([]);
const selectedLists = ref<number[]>(props.filters.lists);
const selectedTags = ref<number[]>(props.filters.tags);
const includeUnsubscribed = ref(props.filters.include_unsubscribed);
const searchQuery = ref(props.filters.search);

const selectedCount = computed(() => selectedContactIds.value.length);

const handleEnrolled = (): void => {
    selectedContactIds.value = [];
};
const hasActiveFilters = computed(
    () =>
        selectedLists.value.length > 0 ||
        selectedTags.value.length > 0 ||
        includeUnsubscribed.value ||
        searchQuery.value !== '',
);

const toggleList = (listId: number): void => {
    const index = selectedLists.value.indexOf(listId);
    if (index === -1) {
        selectedLists.value.push(listId);
    } else {
        selectedLists.value.splice(index, 1);
    }
};

const toggleTag = (tagId: number): void => {
    const index = selectedTags.value.indexOf(tagId);
    if (index === -1) {
        selectedTags.value.push(tagId);
    } else {
        selectedTags.value.splice(index, 1);
    }
};

const clearFilters = (): void => {
    selectedLists.value = [];
    selectedTags.value = [];
    includeUnsubscribed.value = false;
    searchQuery.value = '';
};

const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (selectedLists.value.length > 0) {
        query.lists = selectedLists.value.join(',');
    }
    if (selectedTags.value.length > 0) {
        query.tags = selectedTags.value.join(',');
    }
    if (includeUnsubscribed.value) {
        query.include_unsubscribed = '1';
    }
    if (searchQuery.value !== '') {
        query.search = searchQuery.value;
    }

    router.visit(contactsIndex({ query }).url, {
        preserveState: true,
        preserveScroll: true,
    });
};

const debouncedSearch = useDebounceFn(applyFilters, 300);

watch([selectedLists, selectedTags, includeUnsubscribed], applyFilters, {
    deep: true,
});

watch(searchQuery, debouncedSearch);

const selectedListNames = computed(() =>
    props.lists
        .filter((l) => selectedLists.value.includes(l.id))
        .map((l) => l.name),
);

const selectedTagNames = computed(() =>
    props.tags
        .filter((t) => selectedTags.value.includes(t.id))
        .map((t) => t.name),
);

const exportUrl = computed(() => {
    const params = new URLSearchParams();

    if (selectedLists.value.length > 0) {
        params.set('lists', selectedLists.value.join(','));
    }
    if (selectedTags.value.length > 0) {
        params.set('tags', selectedTags.value.join(','));
    }
    if (includeUnsubscribed.value) {
        params.set('include_unsubscribed', '1');
    }
    if (searchQuery.value !== '') {
        params.set('search', searchQuery.value);
    }

    const queryString = params.toString();

    return '/contacts/export' + (queryString ? '?' + queryString : '');
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Contacts" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold text-foreground">
                            Contacts
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Track subscribers and their subscription status.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Unsubscribed contacts are hidden by default.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button as-child variant="outline" size="sm">
                            <a :href="exportUrl">
                                <Download class="mr-2 size-4" />
                                Export
                            </a>
                        </Button>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="contactsImport().url">
                                Import contacts
                            </Link>
                        </Button>
                        <Button size="sm" @click="showCreateModal = true">
                            Create contact
                        </Button>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <Search
                            class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="searchQuery"
                            type="search"
                            aria-label="Search contacts"
                            placeholder="Search by name or email..."
                            class="h-9 w-64 pl-9"
                        />
                    </div>

                    <DropdownMenu v-if="props.lists.length > 0">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                <Filter class="mr-2 size-4" />
                                Lists
                                <span
                                    v-if="selectedLists.length > 0"
                                    class="ml-1 rounded-full bg-primary px-1.5 text-xs text-primary-foreground"
                                >
                                    {{ selectedLists.length }}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-56">
                            <DropdownMenuLabel
                                >Filter by list</DropdownMenuLabel
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                v-for="list in props.lists"
                                :key="list.id"
                                :checked="selectedLists.includes(list.id)"
                                @select.prevent="toggleList(list.id)"
                            >
                                {{ list.name }}
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu v-if="props.tags.length > 0">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                <Filter class="mr-2 size-4" />
                                Tags
                                <span
                                    v-if="selectedTags.length > 0"
                                    class="ml-1 rounded-full bg-primary px-1.5 text-xs text-primary-foreground"
                                >
                                    {{ selectedTags.length }}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-56">
                            <DropdownMenuLabel>Filter by tag</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                v-for="tag in props.tags"
                                :key="tag.id"
                                :checked="selectedTags.includes(tag.id)"
                                @select.prevent="toggleTag(tag.id)"
                            >
                                {{ tag.name }}
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm">
                                <Filter class="mr-2 size-4" />
                                Status
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-56">
                            <DropdownMenuLabel
                                >Status options</DropdownMenuLabel
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                :checked="includeUnsubscribed"
                                @select.prevent="
                                    includeUnsubscribed = !includeUnsubscribed
                                "
                            >
                                Include unsubscribed
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                    >
                        <X class="mr-1 size-4" />
                        Clear filters
                    </Button>
                </div>

                <div
                    v-if="hasActiveFilters"
                    class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                >
                    <span>Showing contacts</span>
                    <template v-if="selectedListNames.length > 0">
                        <span>in lists:</span>
                        <span
                            v-for="name in selectedListNames"
                            :key="name"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-foreground"
                        >
                            {{ name }}
                        </span>
                    </template>
                    <template v-if="selectedTagNames.length > 0">
                        <span>with tags:</span>
                        <span
                            v-for="name in selectedTagNames"
                            :key="name"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-foreground"
                        >
                            {{ name }}
                        </span>
                    </template>
                    <span v-if="includeUnsubscribed"
                        >(including unsubscribed)</span
                    >
                </div>

                <div
                    v-if="selectedCount > 0"
                    class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2.5"
                >
                    <p class="text-sm font-medium text-foreground">
                        {{ selectedCount }}
                        {{ selectedCount === 1 ? 'contact' : 'contacts' }}
                        selected
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            size="sm"
                            @click="showAddToSequenceModal = true"
                        >
                            <ListPlus class="mr-2 size-4" />
                            Add to sequence
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="selectedContactIds = []"
                        >
                            <X class="mr-1 size-4" />
                            Clear
                        </Button>
                    </div>
                </div>

                <div class="mt-6">
                    <ContactTable
                        v-model:selected="selectedContactIds"
                        :contacts="props.contacts.data"
                        selectable
                    >
                        <template v-if="hasActiveFilters" #empty>
                            No contacts match these filters.
                            <button
                                type="button"
                                data-test="empty-clear-filters"
                                class="font-semibold text-primary underline-offset-4 hover:underline"
                                @click="clearFilters"
                            >
                                Clear filters
                            </button>
                        </template>
                        <template v-else #empty>
                            No contacts yet.
                            <Link
                                class="font-semibold text-primary underline-offset-4 hover:underline"
                                :href="contactsImport().url"
                            >
                                Import a CSV
                            </Link>
                            or
                            <button
                                class="font-semibold text-primary underline-offset-4 hover:underline"
                                @click="showCreateModal = true"
                            >
                                create one manually</button
                            >.
                        </template>
                    </ContactTable>
                </div>

                <ContactsPagination :contacts="props.contacts" />
            </div>
        </section>
        <CreateContactModal v-model:isOpen="showCreateModal" />
        <AddToSequenceModal
            v-model:isOpen="showAddToSequenceModal"
            :sequences="props.sequences"
            :contact-ids="selectedContactIds"
            @enrolled="handleEnrolled"
        />
    </AppLayout>
</template>
