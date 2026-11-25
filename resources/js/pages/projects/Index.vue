<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { PhFolders, PhMagnifyingGlass, PhPlus } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import ProjectFormDialog from '@/components/projects/ProjectFormDialog.vue';
import ProjectRow from '@/components/projects/ProjectRow.vue';
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
import { useUrlQueryParam } from '@/composables/useUrlQueryParam';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as projectsDestroy,
    index as projectsIndex,
    store as projectsStore,
    update as projectsUpdate,
} from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

interface Project {
    id: number;
    uuid: string;
    name: string;
    newsletters_count?: number;
    sequences_count?: number;
    automations_count?: number;
    visual_automations_count?: number;
    created_at: string | null;
}

const props = defineProps<{
    projects: Project[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: projectsIndex().url,
    },
];

const searchQuery = useUrlQueryParam('search', '', { debounceMs: 200 });

const filteredProjects = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (!query) {
        return props.projects;
    }
    return props.projects.filter((project) =>
        project.name.toLowerCase().includes(query),
    );
});

const showCreate = ref(false);
const createForm = useForm({ name: '' });

const openCreate = (): void => {
    createForm.reset();
    createForm.clearErrors();
    showCreate.value = true;
};

const submitCreate = (): void => {
    createForm.post(projectsStore().url, {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            showCreate.value = false;
        },
    });
};

const editingProject = ref<Project | null>(null);
const editForm = useForm({ name: '' });

const openEdit = (project: Project): void => {
    editForm.clearErrors();
    editForm.name = project.name;
    editingProject.value = project;
};

const submitEdit = (): void => {
    if (!editingProject.value) {
        return;
    }
    editForm.patch(projectsUpdate(editingProject.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingProject.value = null;
        },
    });
};

const deletingProject = ref<Project | null>(null);
const deleteForm = useForm({});

const pluralize = (count: number, singular: string): string =>
    `${count.toLocaleString()} ${singular}${count === 1 ? '' : 's'}`;

const deleteSummary = computed((): string => {
    const project = deletingProject.value;
    if (!project) {
        return '';
    }
    return [
        pluralize(project.newsletters_count ?? 0, 'newsletter'),
        pluralize(project.sequences_count ?? 0, 'sequence'),
        pluralize(project.automations_count ?? 0, 'automation'),
        pluralize(project.visual_automations_count ?? 0, 'visual automation'),
    ].join(', ');
});

const confirmDelete = (): void => {
    if (!deletingProject.value) {
        return;
    }
    deleteForm.delete(projectsDestroy(deletingProject.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deletingProject.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Projects" />

        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        class="text-2xl font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Projects
                    </h1>
                    <p class="mt-1 text-sm" style="color: var(--bm-text-3)">
                        Group newsletters, sequences, and automations under a
                        shared initiative.
                    </p>
                </div>
                <Button size="sm" @click="openCreate">
                    <PhPlus :size="14" weight="bold" />
                    New project
                </Button>
            </div>

            <!-- Toolbar -->
            <div
                class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-end"
                style="
                    background-color: var(--bm-surface);
                    border: 1px solid var(--bm-border);
                    border-radius: var(--bm-radius-md);
                "
            >
                <div
                    class="relative flex items-center focus-within:ring-2 focus-within:ring-ring sm:w-72"
                    style="
                        background-color: var(--bm-base);
                        border: 1px solid var(--bm-border);
                        border-radius: var(--bm-radius);
                    "
                >
                    <PhMagnifyingGlass
                        :size="14"
                        class="ml-2.5 shrink-0"
                        :style="{ color: 'var(--bm-text-4)' }"
                    />
                    <input
                        v-model="searchQuery"
                        type="search"
                        aria-label="Search projects"
                        placeholder="Search projects…"
                        class="w-full bg-transparent px-2.5 py-1.5 text-sm focus-visible:outline-none"
                        style="color: var(--bm-text-1)"
                    />
                </div>
            </div>

            <!-- Table -->
            <div
                style="
                    background-color: var(--bm-surface);
                    border: 1px solid var(--bm-border);
                    border-radius: var(--bm-radius-md);
                    box-shadow: var(--bm-shadow-xs);
                    overflow: hidden;
                "
            >
                <div v-if="props.projects.length === 0" class="p-6">
                    <div
                        class="flex flex-col items-center gap-4 px-6 py-10 text-center"
                        style="
                            border: 1px dashed var(--bm-border);
                            border-radius: var(--bm-radius-md);
                        "
                    >
                        <PhFolders :size="32" style="color: var(--bm-text-4)" />
                        <p class="text-sm" style="color: var(--bm-text-3)">
                            No projects yet. Create one to group related
                            newsletters, sequences, and automations.
                        </p>
                        <Button size="sm" @click="openCreate">
                            <PhPlus :size="14" weight="bold" />
                            Create project
                        </Button>
                    </div>
                </div>

                <div
                    v-else-if="filteredProjects.length === 0"
                    class="px-6 py-10 text-center text-sm"
                    style="color: var(--bm-text-3)"
                >
                    No projects match this search.
                </div>

                <table v-else class="min-w-full text-sm">
                    <thead>
                        <tr
                            style="
                                background-color: var(--bm-surface-2);
                                border-bottom: 1px solid var(--bm-border-strong);
                            "
                        >
                            <th
                                class="px-4 py-2.5 text-left text-[10px] font-semibold tracking-widest uppercase"
                                style="color: var(--bm-text-3)"
                            >
                                Project
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold tracking-widest uppercase"
                                style="color: var(--bm-text-3)"
                            >
                                Created
                            </th>
                            <th class="px-4 py-2.5 text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <ProjectRow
                            v-for="project in filteredProjects"
                            :key="project.id"
                            :project="project"
                            @edit="openEdit(project)"
                            @delete="deletingProject = project"
                        />
                    </tbody>
                </table>
            </div>
        </section>

        <ProjectFormDialog
            v-model:name="createForm.name"
            :open="showCreate"
            title="Create project"
            description="Projects group related newsletters, sequences, and automations under one name."
            submit-label="Create project"
            processing-label="Creating…"
            input-id="create-project-name"
            placeholder="Marketing"
            :error="createForm.errors.name"
            :processing="createForm.processing"
            @update:open="showCreate = $event"
            @submit="submitCreate"
        />

        <ProjectFormDialog
            v-model:name="editForm.name"
            :open="editingProject !== null"
            title="Rename project"
            description="Assigned newsletters, sequences, and automations keep pointing at this project."
            submit-label="Save name"
            processing-label="Saving…"
            input-id="edit-project-name"
            :error="editForm.errors.name"
            :processing="editForm.processing"
            @update:open="(open: boolean) => !open && (editingProject = null)"
            @submit="submitEdit"
        />

        <!-- Delete -->
        <Dialog
            :open="deletingProject !== null"
            @update:open="(open: boolean) => !open && (deletingProject = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete project?</DialogTitle>
                    <DialogDescription>
                        “{{ deletingProject?.name }}” will be deleted. This will
                        unassign {{ deleteSummary }} — the resources themselves
                        are not deleted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="deleteForm.processing"
                        @click="confirmDelete"
                    >
                        {{
                            deleteForm.processing
                                ? 'Deleting…'
                                : 'Delete project'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
