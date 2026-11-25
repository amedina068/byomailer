<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    PhCaretRight,
    PhFunnelSimple,
    PhPencilSimple,
    PhPlus,
    PhSlidersHorizontal,
} from '@phosphor-icons/vue';
import { computed, nextTick, ref, watch } from 'vue';

import ComplianceWarnings from '@/components/ComplianceWarnings.vue';
import InputError from '@/components/InputError.vue';
import TargetingForm from '@/components/newsletters/TargetingForm.vue';
import ConfirmStatusToggleModal from '@/components/sequences/ConfirmStatusToggleModal.vue';
import SequenceStatusBadge from '@/components/sequences/SequenceStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { matchesGlobalDefault } from '@/composables/useGlobalDefaultHint';
import { useStatusToggle } from '@/composables/useStatusToggle';
import { toast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { needsUnsubscribeWarning } from '@/lib/compliance';
import {
    show as sequenceShow,
    index as sequencesIndex,
    update as sequenceUpdate,
} from '@/routes/sequences';
import sequenceContacts from '@/routes/sequences/contacts';
import filtersRoutes from '@/routes/sequences/filters';
import sequenceStatusRoutes from '@/routes/sequences/status';
import stepsRoutes from '@/routes/sequences/steps';
import variablesRoutes from '@/routes/sequences/variables';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterFilter, Segment } from '@/types/newsletters';
import { sequenceStatusLabel, type SequenceStatusOption } from '@/types/sequences';

interface EmailIdentity {
    id: number;
    email: string;
    name?: string | null;
}

interface StepMessage {
    id: number;
    subject: string;
    html_content?: string | null;
    text_content?: string | null;
    email_identity_id?: number | null;
    email_identity?: EmailIdentity | null;
}

interface SequenceStep {
    id: number;
    sequence_id: number;
    delay_days: number;
    delay_type: 'days' | 'hours';
    status: string;
    message?: StepMessage | null;
}

interface MailList {
    id: number;
    name: string;
}

interface TagOption {
    id: number;
    name: string;
}

interface Sequence {
    id: number;
    name: string;
    status: string;
    steps: SequenceStep[];
    filters: NewsletterFilter[];
    segment_id: number | null;
    content_variables: Record<string, string>;
}

interface StatTotals {
    contacts: number;
    unsubscribed: number;
    clicked: number;
}

interface SequenceStatTotals extends StatTotals {
    completed: number;
}

interface MailMetrics {
    total: number;
    sent: number;
    delivered: number;
    pending: number;
    opened: number;
    bounced: number;
    unsubscribed: number;
    complained: number;
    delivery_rate: number | null;
    open_rate: number | null;
    unsubscribe_rate: number | null;
}

interface StatLinks {
    contacts: string;
    completed: string;
    unsubscribed: string;
    clicked: string;
}

interface TopicOption {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

const props = defineProps<{
    sequence: Sequence;
    sequenceStatuses: SequenceStatusOption[];
    segments: Segment[];
    lists: MailList[];
    tags: TagOption[];
    topics?: TopicOption[];
    emailIdentities: EmailIdentity[];
    contactAttributes: string[];
    filterNewsletters?: { id: number; title: string }[];
    filterCountries?: string[];
    filterTimezones?: string[];
    sequenceStats: SequenceStatTotals;
    stepStats: Record<number, StatTotals>;
    sequenceMailMetrics: MailMetrics;
    stepMailMetrics: Record<number, MailMetrics>;
    detectedVariables?: string[];
    variableDefaults?: Record<string, string>;
    globalVariableDefaults?: Record<string, string>;
    statusImpact?: { enrolled_contacts: number; pending_mails_due_now: number };
}>();

const pageTitle = computed(() => `${props.sequence.name} · Sequence`);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sequences',
        href: sequencesIndex().url,
    },
    {
        title: props.sequence.name,
        href: sequenceShow(props.sequence.id).url,
    },
];

const sequenceViewMoreUrls = computed<StatLinks>(() => ({
    contacts: sequenceContacts.index(props.sequence.id).url,
    completed: sequenceContacts.index(
        props.sequence.id,
        { query: { filter: 'completed' } },
    ).url,
    unsubscribed: sequenceContacts.index(
        props.sequence.id,
        { query: { filter: 'unsubscribed' } },
    ).url,
    clicked: sequenceContacts.index(
        props.sequence.id,
        { query: { filter: 'clicked' } },
    ).url,
}));

const sequenceStatCards = computed(() => {
    const links = sequenceViewMoreUrls.value;

    return [
        {
            key: 'contacts',
            label: 'Contacts in sequence',
            description: 'Currently enrolled',
            value: props.sequenceStats.contacts,
            href: links.contacts,
        },
        {
            key: 'completed',
            label: 'Completed',
            description: 'Finished the sequence',
            value: props.sequenceStats.completed,
            href: links.completed,
        },
        {
            key: 'unsubscribed',
            label: 'Unsubscribed',
            description: 'Left via this sequence',
            value: props.sequenceStats.unsubscribed,
            href: links.unsubscribed,
        },
        {
            key: 'clicked',
            label: 'Clicked',
            description: 'Engaged with emails',
            value: props.sequenceStats.clicked,
            href: links.clicked,
        },
    ];
});

const formatNumber = (value: number): string => value.toLocaleString();
const formatRate = (value: number | null | undefined): string =>
    value === null || value === undefined ? '—' : `${value}%`;

const mailMetricCards = (metrics: MailMetrics) => [
    { label: 'Sent', value: formatNumber(metrics.sent) },
    { label: 'Delivered', value: formatNumber(metrics.delivered) },
    { label: 'Deliverability', value: formatRate(metrics.delivery_rate) },
    { label: 'Opened', value: formatNumber(metrics.opened) },
    { label: 'Open rate', value: formatRate(metrics.open_rate) },
    { label: 'Pending', value: formatNumber(metrics.pending) },
    { label: 'Bounced', value: formatNumber(metrics.bounced) },
    { label: 'Unsubscribed', value: formatNumber(metrics.unsubscribed) },
    { label: 'Complained', value: formatNumber(metrics.complained) },
];

const sequenceMailMetricCards = computed(() =>
    mailMetricCards(props.sequenceMailMetrics),
);

const emptyMetrics: MailMetrics = {
    total: 0,
    sent: 0,
    delivered: 0,
    pending: 0,
    opened: 0,
    bounced: 0,
    unsubscribed: 0,
    complained: 0,
    delivery_rate: null,
    open_rate: null,
    unsubscribe_rate: null,
};

const metricsForStep = (stepId: number): MailMetrics =>
    props.stepMailMetrics[stepId] ?? emptyMetrics;

const hasSteps = computed(() => props.sequence.steps.length > 0);

/**
 * The live steps without `{{ unsubscribe_url }}`. Only live steps send, and
 * each step page warns about its own content. A warning only.
 */
const liveStepsWithoutUnsubscribeLink = computed<string[]>(() =>
    props.sequence.steps.flatMap((step, index) =>
        step.status === 'live' &&
        needsUnsubscribeWarning(step.message?.html_content, step.message?.text_content)
            ? [`Step ${index + 1}`]
            : [],
    ),
);
const hasEmailIdentities = computed(() => props.emailIdentities.length > 0);
const isEditingName = ref(false);
const sequenceNameInput = ref<HTMLInputElement | null>(null);
const skipNextBlurSave = ref(false);

const sequenceNameForm = useForm({
    name: props.sequence.name,
});

const defaultEmailIdentityId = computed<string>(() => {
    const firstIdentity = props.emailIdentities[0];

    return firstIdentity ? String(firstIdentity.id) : '';
});

const stepForm = useForm({
    delay_days: 0,
    delay_type: 'days' as 'days' | 'hours',
    email_identity_id: defaultEmailIdentityId.value,
});

const updateStepFormDefaults = (identityId: string) => {
    stepForm.defaults({
        delay_days: 0,
        delay_type: 'days',
        email_identity_id: identityId,
    });
};

watch(
    () => props.emailIdentities,
    () => {
        const identityId = defaultEmailIdentityId.value;

        updateStepFormDefaults(identityId);

        const selectedExists = props.emailIdentities.some(
            (identity) => String(identity.id) === stepForm.email_identity_id,
        );

        if (!selectedExists) {
            stepForm.email_identity_id = identityId;
        }
    },
    { deep: true, immediate: true },
);

const createStep = () => {
    if (stepForm.processing || !hasEmailIdentities.value) {
        return;
    }

    stepForm.reset();
    stepForm.email_identity_id = defaultEmailIdentityId.value;
    stepForm.clearErrors();
    submitStep();
};

const startEditingName = () => {
    isEditingName.value = true;
    nextTick(() => {
        sequenceNameInput.value?.focus();
        sequenceNameInput.value?.select();
    });
};

const cancelEditingName = () => {
    skipNextBlurSave.value = true;
    isEditingName.value = false;
    sequenceNameForm.reset('name');
    sequenceNameForm.clearErrors();
    sequenceNameInput.value?.blur();
};

const saveSequenceName = () => {
    if (sequenceNameForm.processing) {
        return;
    }

    if (sequenceNameForm.name === props.sequence.name) {
        isEditingName.value = false;
        return;
    }

    sequenceNameForm.patch(sequenceUpdate(props.sequence.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
            toast.success('Sequence name updated.');
        },
    });
};

const handleSequenceNameBlur = () => {
    if (skipNextBlurSave.value) {
        skipNextBlurSave.value = false;
        return;
    }

    saveSequenceName();
};

watch(
    () => props.sequence.name,
    (newName) => {
        sequenceNameForm.defaults({ name: newName });
        if (!isEditingName.value) {
            sequenceNameForm.name = newName;
        }
        sequenceNameForm.clearErrors();
    },
    { immediate: true },
);

const submitStep = () => {
    stepForm.post(stepsRoutes.store(props.sequence.id).url, {
        onSuccess: () => {
            stepForm.reset();
        },
    });
};

const statusToggle = useStatusToggle({
    url: () => sequenceStatusRoutes.update(props.sequence.id).url,
    isLive: () => props.sequence.status === 'live',
});

const filtersForm = useForm<{
    filters: NewsletterFilter[];
    segment_id: number | null;
}>({
    filters: props.sequence.filters ?? [],
    segment_id: props.sequence.segment_id ?? null,
});

// This page has 4 forms. Resync a form only when the user opens another
// sequence, so a save of one form does not erase unsaved edits in another.
watch(
    () => props.sequence.id,
    () => {
        filtersForm.filters = props.sequence.filters ?? [];
        filtersForm.segment_id = props.sequence.segment_id ?? null;
    },
);

const saveFilters = () => {
    // Targeting is exclusive: a selected segment clears any custom filters.
    filtersForm
        .transform((data) => ({
            ...data,
            filters: data.segment_id != null ? [] : data.filters,
        }))
        .patch(filtersRoutes.update(props.sequence.id).url, {
            preserveScroll: true,
        });
};

const contentVariablesForm = useForm<{
    content_variables: Record<string, string>;
}>({
    content_variables: props.variableDefaults ?? {},
});

watch(
    () => props.sequence.id,
    () => {
        contentVariablesForm.content_variables = props.variableDefaults ?? {};
    },
);

const isFromGlobalDefault = (variable: string): boolean =>
    matchesGlobalDefault(
        props.globalVariableDefaults,
        variable,
        contentVariablesForm.content_variables[variable],
    );

const saveContentVariables = () => {
    contentVariablesForm.patch(variablesRoutes.update(props.sequence.id).url, {
        preserveScroll: true,
    });
};

const stepDelayLabel = (step: SequenceStep, index: number): string => {
    const unit = (step.delay_type ?? 'days') === 'hours' ? 'hour' : 'day';
    const plural = step.delay_days === 1 ? '' : 's';
    const anchor = index === 0 ? 'after enrollment' : 'after previous email';
    return `Sends ${step.delay_days} ${unit}${plural} ${anchor}`;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="pageTitle" />

        <section class="space-y-5">
            <!-- Header -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-5"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            Sequence
                        </p>
                        <form
                            v-if="isEditingName"
                            class="mt-1 flex flex-wrap items-center gap-2"
                            @submit.prevent="
                                skipNextBlurSave = false;
                                saveSequenceName();
                            "
                        >
                            <input
                                ref="sequenceNameInput"
                                v-model="sequenceNameForm.name"
                                type="text"
                                class="min-w-[220px] rounded-[var(--bm-radius)] border px-2.5 py-1 text-xl font-semibold focus-visible:outline-none"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                                placeholder="Sequence name"
                                @keydown.esc.prevent="cancelEditingName"
                                @blur="handleSequenceNameBlur"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="sequenceNameForm.processing"
                                @pointerdown="skipNextBlurSave = true"
                            >
                                {{ sequenceNameForm.processing ? 'Saving…' : 'Save' }}
                            </Button>
                            <button
                                type="button"
                                class="text-sm"
                                style="color: var(--bm-text-3);"
                                @pointerdown="skipNextBlurSave = true"
                                @click="cancelEditingName"
                            >
                                Cancel
                            </button>
                        </form>
                        <div v-else class="group mt-1 flex items-center gap-2">
                            <h1
                                role="button"
                                tabindex="0"
                                class="truncate rounded-[var(--bm-radius)] text-xl font-semibold transition-colors focus-visible:outline-none"
                                style="color: var(--bm-text-1);"
                                @click="startEditingName"
                                @keydown.enter.prevent="startEditingName"
                                @keydown.space.prevent="startEditingName"
                            >
                                {{ props.sequence.name }}
                            </h1>
                            <button
                                type="button"
                                class="shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
                                title="Edit name"
                                @click="startEditingName"
                            >
                                <PhPencilSimple :size="16" style="color: var(--bm-text-3);" />
                                <span class="sr-only">Edit name</span>
                            </button>
                        </div>
                        <InputError
                            v-if="sequenceNameForm.errors.name"
                            class="mt-2"
                            :message="sequenceNameForm.errors.name"
                        />
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <SequenceStatusBadge
                            :status="props.sequence.status"
                            :statuses="props.sequenceStatuses"
                        />
                        <Switch
                            :model-value="props.sequence.status === 'live'"
                            aria-label="Live"
                            @update:model-value="statusToggle.request"
                        />
                    </div>
                </div>
                <p class="mt-3 text-sm" style="color: var(--bm-text-3);">
                    Draft sequences can be edited freely. Go live when the
                    sequence is ready to start.
                </p>
            </div>

            <!-- Engagement stats -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="card in sequenceStatCards"
                    :key="card.key"
                    class="rounded-[var(--bm-radius)] border p-4"
                    style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
                >
                    <p
                        class="truncate text-[10px] font-semibold uppercase tracking-widest"
                        style="color: var(--bm-text-3);"
                    >
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-semibold tabular-nums"
                        style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                    >
                        {{ formatNumber(card.value) }}
                    </p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <span class="text-xs" style="color: var(--bm-text-3);">
                            {{ card.description }}
                        </span>
                        <Link
                            :href="card.href"
                            class="text-xs font-medium transition hover:underline"
                            style="color: var(--bm-accent);"
                        >
                            View more
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Mail metrics (across all steps) -->
            <div
                class="rounded-[var(--bm-radius-md)] border"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b px-5 py-3"
                    style="border-color: var(--bm-border);"
                >
                    <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                        Email performance
                    </h2>
                    <p class="text-xs tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-3);">
                        {{ formatNumber(props.sequenceMailMetrics.total) }} total
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-5">
                    <div
                        v-for="card in sequenceMailMetricCards"
                        :key="card.label"
                        class="rounded-[var(--bm-radius)] border px-3.5 py-3"
                        style="background-color: var(--bm-base); border-color: var(--bm-border);"
                    >
                        <p
                            class="truncate text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3);"
                        >
                            {{ card.label }}
                        </p>
                        <p
                            class="mt-1.5 text-xl font-semibold tabular-nums"
                            style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                        >
                            {{ card.value }}
                        </p>
                    </div>
                </div>
            </div>

            <ComplianceWarnings
                :without-unsubscribe-link="liveStepsWithoutUnsubscribeLink"
            />

            <!-- Steps -->
            <div
                class="rounded-[var(--bm-radius-md)] border"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b px-5 py-3"
                    style="border-color: var(--bm-border);"
                >
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                            Steps
                        </h2>
                        <span
                            class="px-1.5 py-0.5 text-[10px] tabular-nums"
                            style="font-family: var(--bm-font-mono); background-color: var(--bm-base); border: 1px solid var(--bm-border); color: var(--bm-text-3); border-radius: 3px;"
                        >
                            {{ props.sequence.steps.length }}
                        </span>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!hasEmailIdentities || stepForm.processing"
                        @click="createStep"
                    >
                        <PhPlus :size="14" weight="bold" class="mr-1.5" />
                        {{ stepForm.processing ? 'Adding…' : 'Add step' }}
                    </Button>
                </div>

                <div v-if="hasSteps">
                    <Link
                        v-for="(step, index) in props.sequence.steps"
                        :key="step.id"
                        :href="stepsRoutes.show([props.sequence.id, step.id]).url"
                        class="flex items-center gap-4 border-b px-5 py-3.5 transition-colors last:border-b-0 hover:bg-[var(--bm-surface-2)]"
                        style="border-color: var(--bm-border);"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-[var(--bm-radius)] text-xs font-semibold tabular-nums"
                            style="font-family: var(--bm-font-mono); background-color: var(--bm-accent-bright-dim); color: var(--bm-accent);"
                        >
                            {{ index + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p
                                    class="truncate text-[13px] font-medium"
                                    style="color: var(--bm-text-1);"
                                >
                                    {{ step.message?.subject ?? 'Untitled step' }}
                                </p>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 px-1.5 py-0.5"
                                    :style="{
                                        backgroundColor:
                                            step.status === 'live'
                                                ? 'var(--bm-success-dim)'
                                                : 'var(--bm-surface-2)',
                                        color:
                                            step.status === 'live'
                                                ? 'var(--bm-success)'
                                                : 'var(--bm-text-3)',
                                        fontFamily: 'var(--bm-font-mono)',
                                        fontSize: '10px',
                                        fontWeight: 500,
                                        borderRadius: '3px',
                                    }"
                                >
                                    {{ sequenceStatusLabel(props.sequenceStatuses, step.status) }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs" style="color: var(--bm-text-3);">
                                {{ stepDelayLabel(step, index) }}
                                ·
                                <span
                                    class="tabular-nums"
                                    style="font-family: var(--bm-font-mono);"
                                >{{ formatNumber(stepStats[step.id]?.contacts ?? 0) }}</span>
                                contact{{ (stepStats[step.id]?.contacts ?? 0) === 1 ? '' : 's' }}
                            </p>
                            <div
                                class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px]"
                                style="color: var(--bm-text-3);"
                            >
                                <span>
                                    Sent
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).sent) }}</span>
                                </span>
                                <span>
                                    Delivered
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).delivered) }}</span>
                                </span>
                                <span>
                                    Opened
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).opened) }}</span>
                                    <span class="ml-1" style="color: var(--bm-text-3);">({{ formatRate(metricsForStep(step.id).open_rate) }})</span>
                                </span>
                                <span>
                                    Bounced
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).bounced) }}</span>
                                </span>
                                <span>
                                    Unsub
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).unsubscribed) }}</span>
                                </span>
                                <span v-if="metricsForStep(step.id).complained > 0">
                                    Complained
                                    <span class="ml-1 tabular-nums" style="font-family: var(--bm-font-mono); color: var(--bm-text-1);">{{ formatNumber(metricsForStep(step.id).complained) }}</span>
                                </span>
                            </div>
                        </div>
                        <PhCaretRight :size="16" class="shrink-0" style="color: var(--bm-text-3);" />
                    </Link>
                </div>
                <div
                    v-else
                    class="px-5 py-10 text-center text-sm"
                    style="color: var(--bm-text-3);"
                >
                    No steps yet. Add your first message to start the flow.
                </div>
            </div>

            <!-- Filters -->
            <div
                class="rounded-[var(--bm-radius-md)] border"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
            >
                <div
                    class="border-b px-5 py-3"
                    style="border-color: var(--bm-border);"
                >
                    <div class="flex items-center gap-2">
                        <PhFunnelSimple :size="16" style="color: var(--bm-text-3);" />
                        <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                            Audience filters
                        </h2>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--bm-text-3);">
                        Contacts who don't match are removed from the sequence.
                    </p>
                </div>
                <div class="p-5">
                    <TargetingForm
                        v-model:filters="filtersForm.filters"
                        v-model:segment-id="filtersForm.segment_id"
                        :segments="segments"
                        :lists="lists"
                        :tags="tags"
                        :topics="props.topics ?? []"
                        :attribute-keys="contactAttributes"
                        :filter-newsletters="props.filterNewsletters ?? []"
                        :filter-countries="props.filterCountries ?? []"
                        :filter-timezones="props.filterTimezones ?? []"
                        :processing="filtersForm.processing"
                        :can-edit="true"
                        :require-list-filter="false"
                        :show-back-button="false"
                        save-label="Save filters"
                        :filters-error="filtersForm.errors.filters"
                        @save="saveFilters"
                    />
                </div>
            </div>

            <!-- Variable defaults -->
            <div
                v-if="props.detectedVariables && props.detectedVariables.length > 0"
                class="rounded-[var(--bm-radius-md)] border"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
            >
                <div
                    class="flex items-center gap-2 border-b px-5 py-3"
                    style="border-color: var(--bm-border);"
                >
                    <PhSlidersHorizontal :size="16" style="color: var(--bm-text-3);" />
                    <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                        Variable defaults
                    </h2>
                </div>
                <div class="p-5">
                    <p class="text-sm" style="color: var(--bm-text-3);">
                        Set fallback values for template variables used across steps.
                    </p>
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="variable in props.detectedVariables"
                            :key="variable"
                            class="grid gap-1.5"
                        >
                            <label
                                :for="`seq_var_${variable}`"
                                class="text-sm font-medium"
                                style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                            >
                                {{ variable }}
                            </label>
                            <input
                                :id="`seq_var_${variable}`"
                                v-model="contentVariablesForm.content_variables[variable]"
                                type="text"
                                :placeholder="`Default value for ${variable}`"
                                class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                                :disabled="contentVariablesForm.processing"
                            />
                            <p
                                v-if="isFromGlobalDefault(variable)"
                                aria-live="polite"
                                class="text-xs"
                                style="color: var(--bm-text-4);"
                            >
                                This matches your global default.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <Button
                            :disabled="contentVariablesForm.processing"
                            @click="saveContentVariables"
                        >
                            {{ contentVariablesForm.processing ? 'Saving…' : 'Save defaults' }}
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <ConfirmStatusToggleModal
            :is-open="statusToggle.confirming"
            :is-live="props.sequence.status === 'live'"
            :impact="props.statusImpact"
            label="sequence"
            @update:is-open="(open: boolean) => !open && (statusToggle.confirming = false)"
            @confirm="statusToggle.confirm"
        >
            <ComplianceWarnings
                v-if="props.sequence.status !== 'live'"
                :without-unsubscribe-link="liveStepsWithoutUnsubscribeLink"
            />
        </ConfirmStatusToggleModal>
    </AppLayout>
</template>
