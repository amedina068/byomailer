<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    create as listsCreate,
    index as listsIndex,
    show as listsShow,
} from '@/routes/lists';
import type { BreadcrumbItem } from '@/types';

interface MailList {
    id: number;
    uuid: string;
    name: string;
    contacts_count?: number;
    created_at: string;
}

const props = defineProps<{
    lists: MailList[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Lists',
        href: listsIndex().url,
    },
];

const formatDate = (value?: string | null): string => {
    return value ? new Date(value).toLocaleDateString() : '—';
};

const hasLists = computed(() => props.lists.length > 0);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Lists" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-foreground">
                            Manage lists
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Group contacts into lists. Then import contacts or
                            use the lists in newsletters and automations.
                        </p>
                    </div>
                    <Button as-child size="sm">
                        <Link :href="listsCreate().url"> Create list </Link>
                    </Button>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-foreground">
                            Lists
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Each contact can be in more than one list.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 overflow-x-auto rounded-lg border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead
                            class="bg-muted/50 text-left tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3">Name</th>
                                <th scope="col" class="px-4 py-3">Contacts</th>
                                <th scope="col" class="px-4 py-3">Created</th>
                                <th scope="col" class="px-4 py-3 text-right">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="list in props.lists"
                                :key="list.id"
                                class="bg-card/50"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ list.name }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ list.contacts_count ?? 0 }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ formatDate(list.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        prefetch
                                        class="text-sm font-medium text-primary transition hover:underline"
                                        :href="listsShow(list.id).url"
                                    >
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!hasLists">
                                <td
                                    colspan="4"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No lists yet. Create one above to start
                                    organizing contacts.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
