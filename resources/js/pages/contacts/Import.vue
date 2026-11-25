<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import MailListImportController from '@/actions/App/Http/Controllers/MailListImportController';
import InputError from '@/components/InputError.vue';
import CreateListModal from '@/components/lists/CreateListModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    importMethod as contactsImportRoute,
    index as contactsIndex,
} from '@/routes/contacts';
import type { BreadcrumbItem } from '@/types';

interface MailList {
    id: number;
    name: string;
}

const props = defineProps<{
    lists: MailList[];
    selectedListId?: number | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Contacts',
        href: contactsIndex().url,
    },
    {
        title: 'Import contacts',
        href: contactsImportRoute().url,
    },
];

const importRoute = MailListImportController();
const acceptedFileTypes = '.csv,.xlsx,.xls';

const importDisabled = computed(() => props.lists.length === 0);
const showCreateListModal = ref(false);
const selectedList = ref(
    props.selectedListId ? String(props.selectedListId) : '',
);

// A list made with "Create new list" comes back as selectedListId.
watch(
    () => props.selectedListId,
    (listId) => {
        if (listId) {
            selectedList.value = String(listId);
        }
    },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Import contacts" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Import contacts
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Upload a CSV or Excel file to add subscribers
                            directly into one of your lists.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="contactsIndex().url">
                            Back to contacts
                        </Link>
                    </Button>
                </div>

                <Form
                    :action="importRoute.url"
                    :method="importRoute.method"
                    enctype="multipart/form-data"
                    class="mt-6 space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label
                            for="list_id"
                            class="text-sm font-medium text-foreground"
                        >
                            Add to list
                        </Label>
                        <div class="flex items-center gap-2">
                            <select
                                id="list_id"
                                name="list_id"
                                v-model="selectedList"
                                class="flex-1 rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                                :disabled="importDisabled"
                                required
                            >
                                <option value="" disabled>Select a list</option>
                                <option
                                    v-for="list in props.lists"
                                    :key="list.id"
                                    :value="String(list.id)"
                                >
                                    {{ list.name }}
                                </option>
                            </select>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                @click="showCreateListModal = true"
                                title="Create new list"
                            >
                                <Plus class="size-4" />
                            </Button>
                        </div>
                        <InputError :message="errors.list_id" />
                        <p
                            v-if="importDisabled"
                            class="text-xs text-muted-foreground"
                        >
                            <button
                                type="button"
                                class="text-foreground underline underline-offset-2 hover:no-underline"
                                @click="showCreateListModal = true"
                            >
                                Create a list
                            </button>
                            first to enable imports.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label
                            for="import_file"
                            class="text-sm font-medium text-foreground"
                        >
                            Contacts file
                        </Label>
                        <input
                            id="import_file"
                            type="file"
                            name="file"
                            :accept="acceptedFileTypes"
                            class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground file:mr-4 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            :disabled="importDisabled"
                            required
                        />
                        <InputError :message="errors.file" />
                        <p class="text-xs text-muted-foreground">
                            Supported formats: CSV, XLSX, XLS. Maximum file
                            size:
                            <span class="font-mono">20 MB</span>. The first row
                            must have a column named
                            <span class="font-medium">email</span>.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-between gap-4"
                    >
                        <Button
                            type="submit"
                            :disabled="processing || importDisabled"
                            class="min-w-40"
                        >
                            <Spinner v-if="processing" class="mr-2" />
                            Import contacts
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Columns beyond
                            <span class="font-medium">name</span>,
                            <span class="font-medium">email</span>, and
                            <span class="font-medium">tags</span> are saved to
                            extra attributes and tracked for you. If a contact
                            already exists, the import updates its name and
                            attributes.
                        </p>
                    </div>
                </Form>
            </div>
        </section>

        <CreateListModal
            v-model:is-open="showCreateListModal"
            redirect-to="contacts.import"
        />
    </AppLayout>
</template>
