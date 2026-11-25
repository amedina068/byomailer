<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PhSlidersHorizontal } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import AttributeRow from '@/components/attributes/AttributeRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as attributesIndex, update as attributesUpdate } from '@/routes/attributes';
import { index as contactsIndex } from '@/routes/contacts';
import type { BreadcrumbItem } from '@/types';

interface AttributeListItem {
    id: number;
    name: string;
    default_value: string | null;
}

const props = defineProps<{
    attributes: AttributeListItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Attributes',
        href: attributesIndex().url,
    },
];

const hasAttributes = computed(() => props.attributes.length > 0);

const savingId = ref<number | null>(null);
const errorById = ref<Record<number, string>>({});

const saveDefault = (attribute: AttributeListItem, value: string): void => {
    savingId.value = attribute.id;
    delete errorById.value[attribute.id];

    router.patch(
        attributesUpdate(attribute.id).url,
        { default_value: value },
        {
            preserveScroll: true,
            onError: (errors) => {
                errorById.value[attribute.id] =
                    errors.default_value ?? 'Could not save this default.';
            },
            onFinish: () => {
                savingId.value = null;
            },
        },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Attributes" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-foreground">
                        Attributes
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Set a global default for each contact attribute. When a
                        contact has no value and the newsletter or sequence
                        defines none, emails fall back to the default you set
                        here. Editing a global default does not change
                        newsletters or sequences already saved.
                    </p>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card dark:border-sidebar-border"
            >
                <div v-if="hasAttributes" class="overflow-hidden rounded-xl">
                    <table class="min-w-full text-sm">
                        <thead
                            class="text-left text-[10px] tracking-widest uppercase"
                            style="
                                background-color: var(--bm-surface-2);
                                color: var(--bm-text-3);
                            "
                        >
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">
                                    Attribute
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium">
                                    Global default
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <AttributeRow
                                v-for="attribute in props.attributes"
                                :key="attribute.id"
                                :attribute="attribute"
                                :saving="savingId === attribute.id"
                                :error="errorById[attribute.id]"
                                @save="(value) => saveDefault(attribute, value)"
                                @cancel="delete errorById[attribute.id]"
                            />
                        </tbody>
                    </table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-6 py-16 text-center"
                >
                    <PhSlidersHorizontal
                        :size="32"
                        style="color: var(--bm-text-4)"
                    />
                    <div class="space-y-1">
                        <p
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1)"
                        >
                            No attributes yet
                        </p>
                        <p class="text-sm" style="color: var(--bm-text-3)">
                            Custom fields you add to
                            <Link
                                :href="contactsIndex().url"
                                class="underline underline-offset-2"
                                style="color: var(--bm-text-1)"
                                >contacts</Link
                            >
                            appear here automatically, so you can give each one
                            a fallback value for your emails.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
