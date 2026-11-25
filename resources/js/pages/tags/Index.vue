<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import CreateTagModal from '@/components/tags/CreateTagModal.vue';
import TagRow from '@/components/tags/TagRow.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as tagsIndex } from '@/routes/tags';
import type { BreadcrumbItem } from '@/types';

interface Tag {
    id: number;
    uuid: string;
    name: string;
    contacts_count?: number;
    created_at: string;
}

const props = defineProps<{
    tags: Tag[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tags',
        href: tagsIndex().url,
    },
];

const hasTags = computed(() => props.tags.length > 0);

const showCreateModal = ref(false);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tags" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold text-foreground">
                            Tags
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Lightweight labels for organizing contacts.
                        </p>
                    </div>
                    <Button size="sm" @click="showCreateModal = true">
                        Create tag
                    </Button>
                </div>

                <div
                    class="mt-6 overflow-hidden rounded-lg border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead
                            class="bg-muted/50 text-left tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Contacts</th>
                                <th class="px-4 py-3">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <TagRow
                                v-for="tag in props.tags"
                                :key="tag.id"
                                :tag="tag"
                            />
                            <tr v-if="!hasTags">
                                <td
                                    colspan="3"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No tags defined yet. Create some to segment
                                    contacts.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <CreateTagModal v-model:is-open="showCreateModal" />
    </AppLayout>
</template>
