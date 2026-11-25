<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ProjectBadge from '@/components/projects/ProjectBadge.vue';
import ProjectFilterDropdown from '@/components/projects/ProjectFilterDropdown.vue';
import ProjectQuickAssign from '@/components/projects/ProjectQuickAssign.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { useProjectFilter } from '@/composables/useProjectFilter';
import { toast } from '@/composables/useToast';
import { useUrlQueryParam } from '@/composables/useUrlQueryParam';
import AppLayout from '@/layouts/AppLayout.vue';
import { matchesProjectFilter } from '@/lib/filterByProject';
import {
    create as createRoute,
    destroy as destroyRoute,
    index as indexRoute,
    show as showRoute,
} from '@/routes/visual-automations';
import { sync as visualAutomationProjectsSync } from '@/routes/visual-automations/projects';
import statusRoute from '@/routes/visual-automations/status';

interface VisualAutomation {
    id: number;
    name: string;
    status: string;
    enrollments_count: number;
    created_at: string;
    project_ids?: number[];
}

interface ProjectOption {
    id: number;
    name: string;
}

const props = defineProps<{
    automations: VisualAutomation[];
    projects: ProjectOption[];
}>();

const projectFilter = useUrlQueryParam<string>('project', 'all');

const { validProjectIds } = useProjectFilter(
    () => props.projects,
    projectFilter,
);

const filteredAutomations = computed(() =>
    props.automations.filter((automation) =>
        matchesProjectFilter(
            automation.project_ids,
            projectFilter.value,
            validProjectIds.value,
        ),
    ),
);

const assignedProjects = (automation: VisualAutomation): ProjectOption[] => {
    const ids = automation.project_ids ?? [];
    return props.projects.filter((project) => ids.includes(project.id));
};

const breadcrumbs = [{ title: 'Visual Automations', href: indexRoute().url }];

// Status toggle
const confirmingToggle = ref<VisualAutomation | null>(null);
const togglingStatus = ref(false);

const toggleStatus = () => {
    if (!confirmingToggle.value) return;

    const newStatus =
        confirmingToggle.value.status === 'live' ? 'draft' : 'live';

    togglingStatus.value = true;

    router.patch(
        statusRoute.update(confirmingToggle.value.id).url,
        { status: newStatus },
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmingToggle.value = null;
                togglingStatus.value = false;
            },
            onError: (errors) => {
                togglingStatus.value = false;
                confirmingToggle.value = null;
                if (errors.status) {
                    toast.error(errors.status);
                }
            },
        },
    );
};

// Delete
const confirmingDelete = ref<VisualAutomation | null>(null);
const deletingAutomation = ref(false);

const deleteAutomation = () => {
    if (!confirmingDelete.value) return;

    deletingAutomation.value = true;

    router.delete(destroyRoute(confirmingDelete.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDelete.value = null;
            deletingAutomation.value = false;
        },
        onError: () => {
            deletingAutomation.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Visual Automations" />

        <section
            class="rounded-xl border border-sidebar-border/60 bg-card p-6 shadow-sm dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">
                        Visual Automations
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Multi-step workflows with branching logic.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <ProjectFilterDropdown
                        v-model="projectFilter"
                        :projects="props.projects"
                        align="end"
                    />
                    <Link
                        class="inline-flex items-center rounded-lg border border-border px-4 py-2 text-sm font-medium"
                        :href="createRoute().url"
                    >
                        New automation
                    </Link>
                </div>
            </div>

            <div class="mt-6 grid gap-4">
                <div
                    v-for="automation in filteredAutomations"
                    :key="automation.id"
                    class="rounded-lg border border-border bg-background p-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <Link
                                :href="showRoute(automation.id).url"
                                class="text-sm font-semibold text-foreground hover:underline"
                            >
                                {{ automation.name }}
                            </Link>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{ automation.enrollments_count }}
                                    contacts enrolled
                                </p>
                                <template
                                    v-if="
                                        assignedProjects(automation).length > 0
                                    "
                                >
                                    <ProjectBadge
                                        v-for="project in assignedProjects(
                                            automation,
                                        ).slice(0, 2)"
                                        :key="project.id"
                                        :name="project.name"
                                    />
                                    <span
                                        v-if="
                                            assignedProjects(automation)
                                                .length > 2
                                        "
                                        class="tabular-nums"
                                        style="
                                            font-family: var(--bm-font-mono);
                                            font-size: 10px;
                                            color: var(--bm-text-4);
                                        "
                                        :title="
                                            assignedProjects(automation)
                                                .slice(2)
                                                .map((p) => p.name)
                                                .join(', ')
                                        "
                                    >
                                        +{{
                                            assignedProjects(automation)
                                                .length - 2
                                        }}
                                    </span>
                                </template>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <ProjectQuickAssign
                                v-if="props.projects.length > 0"
                                :project-ids="automation.project_ids ?? []"
                                :options="props.projects"
                                :sync-url="
                                    visualAutomationProjectsSync(automation.id)
                                        .url
                                "
                                :reload-props="['automations']"
                            />
                            <label
                                class="flex items-center gap-2 text-xs"
                                @click.prevent="confirmingToggle = automation"
                            >
                                <Switch
                                    :model-value="automation.status === 'live'"
                                    :aria-label="`Live: ${automation.name}`"
                                    class="pointer-events-none"
                                />
                            </label>
                            <Link
                                class="inline-flex items-center rounded-lg border border-border px-3 py-1 text-xs font-medium text-primary hover:underline"
                                :href="showRoute(automation.id).url"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="text-xs font-medium text-destructive underline-offset-2 hover:underline"
                                @click="confirmingDelete = automation"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>

                <p
                    v-if="!props.automations.length"
                    class="text-sm text-muted-foreground"
                >
                    No visual automations yet. Create one to get started.
                </p>
                <p
                    v-else-if="!filteredAutomations.length"
                    class="text-sm text-muted-foreground"
                >
                    No visual automations in this project.
                </p>
            </div>
        </section>

        <!-- Status toggle confirmation -->
        <Dialog
            :open="!!confirmingToggle"
            @update:open="(open: boolean) => !open && (confirmingToggle = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            confirmingToggle?.status === 'live'
                                ? 'Pause automation?'
                                : 'Go live?'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            confirmingToggle?.status === 'live'
                                ? 'While the automation is paused, it enrolls no new contacts and runs no steps. Contacts already in the workflow continue when you resume it.'
                                : 'The automation enrolls each contact that matches the trigger.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="confirmingToggle = null">
                        Cancel
                    </Button>
                    <Button :disabled="togglingStatus" @click="toggleStatus">
                        {{
                            togglingStatus
                                ? 'Saving...'
                                : confirmingToggle?.status === 'live'
                                  ? 'Pause'
                                  : 'Go live'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete confirmation -->
        <Dialog
            :open="!!confirmingDelete"
            @update:open="(open: boolean) => !open && (confirmingDelete = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle> Delete automation? </DialogTitle>
                    <DialogDescription>
                        This will permanently delete "{{
                            confirmingDelete?.name
                        }}" and all enrollment data. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="confirmingDelete = null">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="deletingAutomation"
                        @click="deleteAutomation"
                    >
                        {{ deletingAutomation ? 'Deleting...' : 'Delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
