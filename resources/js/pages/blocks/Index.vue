<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface Block {
    id: number;
    uuid: string;
    name: string;
    html_content: string;
    created_at: string;
    updated_at: string;
}

const props = defineProps<{
    blocks: Block[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blocks',
        href: '/blocks',
    },
];

const formatDate = (value?: string | null): string => {
    return value ? new Date(value).toLocaleDateString() : '—';
};

const hasBlocks = computed(() => props.blocks.length > 0);

const blockToDelete = ref<Block | null>(null);
const deletingBlock = ref(false);
const deleteTitle = computed(
    () => `Delete the block "${blockToDelete.value?.name ?? ''}"?`,
);

const deleteBlock = () => {
    if (!blockToDelete.value) {
        return;
    }

    deletingBlock.value = true;

    router.delete(`/blocks/${blockToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            blockToDelete.value = null;
        },
        onFinish: () => {
            deletingBlock.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Blocks" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-foreground">
                            Manage blocks
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Create reusable content blocks to insert into your
                            emails using
                            <code
                                class="text-xs"
                                v-text="'{{ block.name }}'"
                            ></code>
                            syntax.
                        </p>
                    </div>
                    <Button as-child size="sm">
                        <Link href="/blocks/create"> Create block </Link>
                    </Button>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-foreground">
                            Blocks
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Reusable HTML snippets for your email templates.
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
                                <th scope="col" class="px-4 py-3">Usage</th>
                                <th scope="col" class="px-4 py-3">Updated</th>
                                <th scope="col" class="px-4 py-3 text-right">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="block in props.blocks"
                                :key="block.id"
                                class="bg-card/50"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ block.name }}
                                </td>
                                <td class="px-4 py-3">
                                    <code
                                        class="text-xs text-muted-foreground"
                                        v-text="
                                            '{{ block.' + block.name + ' }}'
                                        "
                                    ></code>
                                </td>
                                <td class="px-4 py-3">
                                    {{ formatDate(block.updated_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div
                                        class="flex items-center justify-end gap-3"
                                    >
                                        <Link
                                            class="text-sm font-medium text-primary transition hover:underline"
                                            :href="`/blocks/${block.id}/edit`"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            type="button"
                                            class="text-sm font-medium text-destructive transition hover:underline"
                                            @click="blockToDelete = block"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!hasBlocks">
                                <td
                                    colspan="4"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No blocks yet. Create one above to start
                                    building reusable content.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <ConfirmDialog
            :is-open="blockToDelete !== null"
            :title="deleteTitle"
            description="An email that uses this block shows nothing in its place. You cannot undo this action."
            confirm-label="Delete"
            :processing="deletingBlock"
            @update:is-open="(open) => !open && (blockToDelete = null)"
            @confirm="deleteBlock"
        />
    </AppLayout>
</template>
