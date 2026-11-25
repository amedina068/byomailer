<script setup lang="ts">
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';

import { Head, Link, useForm } from '@inertiajs/vue3';
import { Background } from '@vue-flow/background';
import type { Edge, Node, NodeTypesObject } from '@vue-flow/core';
import { VueFlow } from '@vue-flow/core';
import { computed, provide, ref, watchEffect } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    type AddStepContext,
    type BuilderContext,
    builderKey,
} from '@/components/visual-automations/builderContext';
import {
    calculatePlaceholders,
    layoutWorkflow,
    type WorkflowEdge,
    type WorkflowNode,
} from '@/components/visual-automations/layoutWorkflow';
import ActionNode from '@/components/visual-automations/nodes/ActionNode.vue';
import AddStepNode from '@/components/visual-automations/nodes/AddStepNode.vue';
import ConditionNode from '@/components/visual-automations/nodes/ConditionNode.vue';
import DelayNode from '@/components/visual-automations/nodes/DelayNode.vue';
import InsertStepNode from '@/components/visual-automations/nodes/InsertStepNode.vue';
import TriggerNode from '@/components/visual-automations/nodes/TriggerNode.vue';
import type {
    ActionType,
    ConditionBranchType,
    SelectOption,
    WorkflowNodeType,
} from '@/components/visual-automations/types';
import { useStatusToggle } from '@/composables/useStatusToggle';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as indexRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/routes/visual-automations';
import automationContacts from '@/routes/visual-automations/contacts';
import nodeContacts from '@/routes/visual-automations/nodes/contacts';
import statusRoute from '@/routes/visual-automations/status';

type ViewMode = 'create' | 'edit';

interface Automation {
    id: number;
    name: string;
    status: string;
    workflow: { nodes: WorkflowNode[]; edges: WorkflowEdge[] };
    enrollments_count: number;
}

const props = defineProps<{
    mode: ViewMode;
    automation: Automation | null;
    lists: SelectOption[];
    tags: SelectOption[];
    topics?: SelectOption[];
    sequences: SelectOption[];
    nodeStepCounts?: Record<string, number>;
}>();

const nodeTypes: NodeTypesObject = {
    trigger: TriggerNode,
    action: ActionNode,
    delay: DelayNode,
    condition: ConditionNode,
    'add-step': AddStepNode,
    'insert-step': InsertStepNode,
};

// --- helpers for default data ---

const pickFirst = (options: SelectOption[]): number | null =>
    options.length ? options[0].id : null;

const defaultTriggerType = (): string => {
    if (props.lists.length) return 'contact_added_to_list';
    if (props.tags.length) return 'tag_added';
    if (props.sequences.length) return 'added_to_sequence';
    return 'contact_added_to_list';
};

const triggerValueFor = (type: string): number | null => {
    switch (type) {
        case 'contact_added_to_list':
        case 'removed_from_list':
            return pickFirst(props.lists);
        case 'tag_added':
        case 'removed_tag':
            return pickFirst(props.tags);
        case 'added_to_sequence':
        case 'sequence_finished':
            return pickFirst(props.sequences);
        default:
            return null;
    }
};

const defaultActionType = (): ActionType => {
    if (props.tags.length) return 'add_tag';
    if (props.lists.length) return 'add_list';
    if (props.sequences.length) return 'add_sequence';
    return 'unsubscribe_contact';
};

const actionValueFor = (type: ActionType): number | null => {
    switch (type) {
        case 'add_tag':
        case 'remove_tag':
            return pickFirst(props.tags);
        case 'add_list':
        case 'remove_list':
            return pickFirst(props.lists);
        case 'add_sequence':
            return pickFirst(props.sequences);
        default:
            return null;
    }
};

const defaultConditionType = (): ConditionBranchType => {
    if (props.tags.length) return 'has_tag';
    if (props.lists.length) return 'in_list';
    if (props.topics?.length) return 'subscribed_to_topic';
    if (props.sequences.length) return 'in_sequence';
    return 'has_tag';
};

const conditionValueFor = (type: ConditionBranchType): number | null => {
    switch (type) {
        case 'has_tag':
        case 'not_has_tag':
            return pickFirst(props.tags);
        case 'in_list':
        case 'not_in_list':
            return pickFirst(props.lists);
        case 'subscribed_to_topic':
        case 'not_subscribed_to_topic':
            return pickFirst(props.topics ?? []);
        case 'in_sequence':
        case 'not_in_sequence':
            return pickFirst(props.sequences);
        default:
            return null;
    }
};

// --- workflow state ---

const buildDefaultWorkflow = (): {
    nodes: WorkflowNode[];
    edges: WorkflowEdge[];
} => {
    const tType = defaultTriggerType();
    const aType = defaultActionType();
    return {
        nodes: [
            {
                id: 'trigger-1',
                type: 'trigger',
                position: { x: 0, y: 0 },
                data: {
                    trigger_type: tType,
                    value: triggerValueFor(tType),
                },
            },
            {
                id: 'action-1',
                type: 'action',
                position: { x: 0, y: 0 },
                data: {
                    action_type: aType,
                    value: actionValueFor(aType),
                },
            },
        ],
        edges: [{ id: 'edge-1', source: 'trigger-1', target: 'action-1' }],
    };
};

const initial = props.automation?.workflow ?? buildDefaultWorkflow();

const workflowNodes = ref<WorkflowNode[]>(
    JSON.parse(JSON.stringify(initial.nodes)),
);
const workflowEdges = ref<WorkflowEdge[]>(
    JSON.parse(JSON.stringify(initial.edges)),
);

// --- layout computation ---

const renderedNodes = ref<Node[]>([]);
const renderedEdges = ref<Edge[]>([]);

watchEffect(() => {
    const positioned = layoutWorkflow(workflowNodes.value, workflowEdges.value);

    const placeholders = calculatePlaceholders(positioned, workflowEdges.value);

    // Separate endpoint placeholders (open handles) from insert placeholders (on existing edges)
    const endpointPlaceholders = placeholders.filter((p) => !p.insertOnEdgeId);
    const insertPlaceholders = placeholders.filter((p) => !!p.insertOnEdgeId);

    const counts = props.nodeStepCounts ?? {};

    const realNodes: Node[] = positioned.map((n) => ({
        id: n.id,
        type: n.type,
        position: n.position,
        data: { ...n.data, contactCount: counts[n.id] ?? 0 },
        draggable: false,
    }));

    const endpointNodes: Node[] = endpointPlaceholders.map((p) => ({
        id: p.id,
        type: 'add-step',
        position: p.position,
        data: {
            sourceId: p.sourceId,
            sourceHandle: p.sourceHandle,
            label: p.label,
        },
        draggable: false,
    }));

    const insertNodes: Node[] = insertPlaceholders.map((p) => ({
        id: p.id,
        type: 'insert-step',
        position: p.position,
        data: {
            sourceId: p.sourceId,
            sourceHandle: p.sourceHandle,
            insertOnEdgeId: p.insertOnEdgeId,
        },
        draggable: false,
    }));

    renderedNodes.value = [...realNodes, ...endpointNodes, ...insertNodes];

    const realEdges: Edge[] = workflowEdges.value.map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        sourceHandle: e.sourceHandle ?? undefined,
        targetHandle: e.targetHandle ?? undefined,
        animated: false,
    }));

    const endpointEdges: Edge[] = endpointPlaceholders.map((p) => ({
        id: `${p.id}-edge`,
        source: p.sourceId,
        target: p.id,
        sourceHandle: p.sourceHandle ?? undefined,
        animated: true,
        style: { strokeDasharray: '5 5', opacity: 0.4 },
    }));

    renderedEdges.value = [...realEdges, ...endpointEdges];
});

// --- node CRUD ---

const generateId = (prefix: string): string =>
    `${prefix}-${crypto.randomUUID().slice(0, 12)}`;

const createNode = (type: WorkflowNodeType): WorkflowNode => {
    switch (type) {
        case 'trigger': {
            const t = defaultTriggerType();
            return {
                id: generateId('trigger'),
                type: 'trigger',
                position: { x: 0, y: 0 },
                data: { trigger_type: t, value: triggerValueFor(t) },
            };
        }
        case 'action': {
            const a = defaultActionType();
            return {
                id: generateId('action'),
                type: 'action',
                position: { x: 0, y: 0 },
                data: { action_type: a, value: actionValueFor(a) },
            };
        }
        case 'delay':
            return {
                id: generateId('delay'),
                type: 'delay',
                position: { x: 0, y: 0 },
                data: { duration: 1, unit: 'days' },
            };
        case 'condition':
        default: {
            const c = defaultConditionType();
            return {
                id: generateId('condition'),
                type: 'condition',
                position: { x: 0, y: 0 },
                data: { condition_type: c, value: conditionValueFor(c) },
            };
        }
    }
};

// --- status toggle ---

const automationStatus = ref(props.automation?.status ?? 'draft');

const isEditable = computed(
    () => props.mode === 'create' || automationStatus.value !== 'live',
);

const hasConnection = (nodeId: string, handle: string | null): boolean =>
    workflowEdges.value.some(
        (e) => e.source === nodeId && (e.sourceHandle ?? null) === handle,
    );

const addNode = (type: WorkflowNodeType, context: AddStepContext) => {
    if (!isEditable.value) return;

    // Insert between existing nodes (splice into an edge)
    if (context.insertOnEdgeId) {
        const edgeIndex = workflowEdges.value.findIndex(
            (e) => e.id === context.insertOnEdgeId,
        );
        if (edgeIndex === -1) return;

        const existingEdge = workflowEdges.value[edgeIndex];
        const node = createNode(type);

        workflowNodes.value = [...workflowNodes.value, node];

        // Replace old edge with: source→newNode, newNode→oldTarget
        const newEdges = [...workflowEdges.value];
        newEdges.splice(edgeIndex, 1);
        newEdges.push(
            {
                id: generateId('edge'),
                source: existingEdge.source,
                target: node.id,
                sourceHandle: existingEdge.sourceHandle,
            },
            {
                id: generateId('edge'),
                source: node.id,
                target: existingEdge.target,
            },
        );
        workflowEdges.value = newEdges;
        return;
    }

    if (hasConnection(context.sourceId, context.sourceHandle)) {
        return;
    }

    const node = createNode(type);

    workflowNodes.value = [...workflowNodes.value, node];
    workflowEdges.value = [
        ...workflowEdges.value,
        {
            id: generateId('edge'),
            source: context.sourceId,
            target: node.id,
            sourceHandle: context.sourceHandle,
        },
    ];
};

const updateNodeData = (nodeId: string, updates: Record<string, unknown>) => {
    if (!isEditable.value) return;
    workflowNodes.value = workflowNodes.value.map((node) => {
        if (node.id !== nodeId) return node;
        return { ...node, data: { ...node.data, ...updates } };
    });
};

const removeNode = (nodeId: string) => {
    const node = workflowNodes.value.find((n) => n.id === nodeId);
    if (!node || node.type === 'trigger') return;

    // Find incoming edge to this node
    const incomingEdge = workflowEdges.value.find((e) => e.target === nodeId);

    // Find outgoing edge from this node (only for non-condition single-output nodes)
    const outgoingEdges = workflowEdges.value.filter(
        (e) => e.source === nodeId,
    );

    // Remove the node
    workflowNodes.value = workflowNodes.value.filter((n) => n.id !== nodeId);

    // Remove all edges connected to this node
    workflowEdges.value = workflowEdges.value.filter(
        (e) => e.source !== nodeId && e.target !== nodeId,
    );

    // Reconnect: if the removed node had exactly one outgoing edge and one incoming edge,
    // connect the parent to the child
    if (incomingEdge && outgoingEdges.length === 1) {
        workflowEdges.value = [
            ...workflowEdges.value,
            {
                id: generateId('edge'),
                source: incomingEdge.source,
                target: outgoingEdges[0].target,
                sourceHandle: incomingEdge.sourceHandle,
            },
        ];
    }
    // If condition node with multiple outputs, just disconnect the children (they become orphans and get removed)
    if (node.type === 'condition' && outgoingEdges.length > 1) {
        // Remove orphaned subtrees
        const reachable = new Set<string>();
        const trigger = workflowNodes.value.find((n) => n.type === 'trigger');
        if (trigger) {
            const queue = [trigger.id];
            while (queue.length) {
                const current = queue.shift()!;
                if (reachable.has(current)) continue;
                reachable.add(current);
                for (const edge of workflowEdges.value) {
                    if (edge.source === current) {
                        queue.push(edge.target);
                    }
                }
            }
        }

        workflowNodes.value = workflowNodes.value.filter((n) =>
            reachable.has(n.id),
        );
        workflowEdges.value = workflowEdges.value.filter(
            (e) => reachable.has(e.source) && reachable.has(e.target),
        );
    }
};

// --- delete confirmation modal ---

const confirmingDeleteNodeId = ref<string | null>(null);

const confirmRemoveNode = (nodeId: string) => {
    confirmingDeleteNodeId.value = nodeId;
};

const executeRemoveNode = () => {
    if (confirmingDeleteNodeId.value) {
        removeNode(confirmingDeleteNodeId.value);
        confirmingDeleteNodeId.value = null;
    }
};

// --- add step modal ---

const isAddStepModalOpen = ref(false);
const pendingAddContext = ref<AddStepContext | null>(null);

const openAddStepModal = (context: AddStepContext) => {
    if (!isEditable.value) return;
    pendingAddContext.value = context;
    isAddStepModalOpen.value = true;
};

const closeAddStepModal = () => {
    pendingAddContext.value = null;
    isAddStepModalOpen.value = false;
};

const addNodeFromModal = (type: Exclude<WorkflowNodeType, 'trigger'>) => {
    if (!pendingAddContext.value) return;
    addNode(type, pendingAddContext.value);
    closeAddStepModal();
};

const addableStepOptions: Array<{
    type: Exclude<WorkflowNodeType, 'trigger'>;
    title: string;
    description: string;
}> = [
    {
        type: 'action',
        title: 'Action',
        description: 'Add, remove, or unsubscribe contacts automatically.',
    },
    {
        type: 'delay',
        title: 'Delay',
        description: 'Pause the workflow for minutes, hours, or days.',
    },
    {
        type: 'condition',
        title: 'Condition',
        description: 'Split contacts based on tags, lists, or sequences.',
    },
];

// --- provide builder context ---

provide<BuilderContext>(builderKey, {
    get lists() {
        return props.lists;
    },
    get tags() {
        return props.tags;
    },
    get topics() {
        return props.topics ?? [];
    },
    get sequences() {
        return props.sequences;
    },
    get isEditable() {
        return isEditable.value;
    },
    nodeContactsUrl: props.automation
        ? (nodeId: string) =>
              nodeContacts.index([props.automation!.id, nodeId]).url
        : null,
    updateNodeData,
    confirmRemoveNode,
    openAddStepModal,
});

// --- form submission ---

const workflowFormData = (): { nodes: object[]; edges: object[] } => ({
    nodes: workflowNodes.value,
    edges: workflowEdges.value,
});

const form = useForm({
    name: props.automation?.name ?? '',
    workflow: workflowFormData(),
});

const editedState = (): string =>
    JSON.stringify({ name: form.name, ...workflowFormData() });
const savedState = ref(editedState());

const saveWorkflow = (onSaved?: () => void, onFailed?: () => void) => {
    form.workflow = workflowFormData();
    form.put(updateRoute(props.automation!.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            savedState.value = editedState();
            onSaved?.();
        },
        onError: () => onFailed?.(),
    });
};

const statusToggle = useStatusToggle({
    url: () => statusRoute.update(props.automation!.id).url,
    isLive: () => automationStatus.value === 'live',
    unsavedChanges: {
        isDirty: () => editedState() !== savedState.value,
        save: saveWorkflow,
    },
    onChanged: (status) => (automationStatus.value = status),
});

const breadcrumbs = computed(() => {
    const crumbs = [{ title: 'Visual Automations', href: indexRoute().url }];
    if (props.mode === 'edit' && props.automation) {
        crumbs.push({ title: props.automation.name, href: '#' });
    } else {
        crumbs.push({ title: 'Create', href: '#' });
    }
    return crumbs;
});

const pageTitle = computed(() =>
    props.mode === 'edit'
        ? `${props.automation?.name ?? 'Edit'}`
        : 'Create Visual Automation',
);

const draftSequenceIds = computed(() => {
    const ids = new Set<number>();
    for (const sequence of props.sequences) {
        if (sequence.status && sequence.status !== 'live') {
            ids.add(sequence.id);
        }
    }
    return ids;
});

const hasAddSequenceWithDraft = computed(() =>
    workflowNodes.value.some((node) => {
        if (node.type !== 'action') {
            return false;
        }
        const data = (node.data ?? {}) as {
            action_type?: ActionType;
            value?: number | null;
        };
        return (
            data.action_type === 'add_sequence' &&
            data.value != null &&
            draftSequenceIds.value.has(data.value)
        );
    }),
);

const draftSequenceFormError = computed(() =>
    hasAddSequenceWithDraft.value
        ? 'Automations can only add contacts to live sequences. Activate the referenced sequence(s) first.'
        : null,
);

const submit = () => {
    form.workflow = workflowFormData();

    if (props.mode === 'edit' && props.automation) {
        saveWorkflow();
    } else {
        form.post(storeRoute().url);
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="pageTitle" />

        <section
            class="space-y-6 rounded-xl border border-sidebar-border/60 bg-card p-6 shadow-sm dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{
                            mode === 'edit'
                                ? automation?.name
                                : 'Visual Automation Builder'
                        }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Design multi-step workflows with triggers, actions,
                        delays, and branching conditions.
                    </p>
                </div>
                <div
                    v-if="mode === 'edit' && automation"
                    class="flex items-center gap-3"
                >
                    <Link
                        :href="automationContacts.index(automation.id).url"
                        class="text-xs text-muted-foreground hover:text-primary hover:underline"
                    >
                        {{ automation.enrollments_count }} enrolled
                    </Link>
                    <button
                        type="button"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none"
                        :class="
                            automationStatus === 'live'
                                ? 'bg-emerald-500'
                                : 'bg-muted'
                        "
                        role="switch"
                        aria-label="Live"
                        :aria-checked="automationStatus === 'live'"
                        @click="statusToggle.request"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="
                                automationStatus === 'live'
                                    ? 'translate-x-5'
                                    : 'translate-x-0'
                            "
                        />
                    </button>
                    <span
                        class="text-sm font-medium"
                        :class="
                            automationStatus === 'live'
                                ? 'text-emerald-500'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ automationStatus === 'live' ? 'Live' : 'Draft' }}
                    </span>
                </div>
            </div>

            <div class="grid gap-2">
                <label class="text-sm font-medium" for="name">
                    Automation name
                </label>
                <input
                    id="name"
                    v-model="form.name"
                    name="name"
                    type="text"
                    :disabled="!isEditable"
                    class="rounded-lg border border-border bg-background px-3 py-2 text-sm disabled:opacity-50"
                    placeholder="New subscriber nurture"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div
                class="h-[600px] rounded-xl border border-dashed border-border"
            >
                <VueFlow
                    :nodes="renderedNodes"
                    :edges="renderedEdges"
                    class="h-full w-full"
                    fit-view-on-init
                    :nodes-draggable="false"
                    :nodes-connectable="false"
                    :node-types="nodeTypes"
                >
                    <Background pattern-color="var(--border)" />
                </VueFlow>
            </div>

            <InputError
                :message="
                    form.errors.workflow ??
                    form.errors['workflow.nodes'] ??
                    form.errors['workflow.edges']
                "
            />

            <InputError
                v-if="draftSequenceFormError"
                :message="draftSequenceFormError"
            />

            <div class="flex items-center justify-between">
                <Link
                    :href="indexRoute().url"
                    class="text-sm font-medium text-muted-foreground underline-offset-4 hover:underline"
                >
                    Cancel
                </Link>
                <Button
                    v-if="isEditable"
                    type="button"
                    :disabled="form.processing || hasAddSequenceWithDraft"
                    @click="submit"
                >
                    {{ form.processing ? 'Saving...' : 'Save automation' }}
                </Button>
            </div>
        </section>

        <Dialog
            :open="isAddStepModalOpen"
            @update:open="(open: boolean) => !open && closeAddStepModal()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add next step</DialogTitle>
                    <DialogDescription>
                        Choose what comes next in this automation.
                    </DialogDescription>
                </DialogHeader>
                <div class="mt-4 grid gap-3">
                    <Button
                        v-for="option in addableStepOptions"
                        :key="option.type"
                        variant="outline"
                        class="flex h-auto flex-col items-start gap-1 text-left"
                        @click="addNodeFromModal(option.type)"
                    >
                        <span class="text-sm font-semibold text-foreground">
                            {{ option.title }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ option.description }}
                        </span>
                    </Button>
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="closeAddStepModal">
                        Cancel
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="confirmingDeleteNodeId !== null"
            @update:open="
                (open: boolean) => !open && (confirmingDeleteNodeId = null)
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete step</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove this step from the
                        automation? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        variant="ghost"
                        @click="confirmingDeleteNodeId = null"
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="executeRemoveNode">
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="statusToggle.confirming"
            @update:open="
                (open: boolean) => !open && (statusToggle.confirming = false)
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            automationStatus === 'live'
                                ? 'Pause automation'
                                : 'Go live'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            automationStatus === 'live'
                                ? 'While the automation is paused, it enrolls no new contacts and runs no steps. Contacts already in the workflow continue when you resume it.'
                                : 'When the automation is live, it starts to process enrolled contacts. You cannot edit it while it is live. Unsaved changes are saved first.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        variant="ghost"
                        @click="statusToggle.confirming = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        :variant="
                            automationStatus === 'live' ? 'outline' : 'default'
                        "
                        :disabled="statusToggle.processing"
                        @click="statusToggle.confirm"
                    >
                        {{ automationStatus === 'live' ? 'Pause' : 'Go live' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
