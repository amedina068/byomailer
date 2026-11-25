<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { PhCheck, PhPaperPlaneTilt } from '@phosphor-icons/vue';
import { computed, onMounted, ref, watch } from 'vue';

import NewsletterTestMailController from '@/actions/App/Http/Controllers/NewsletterTestMailController';
import ComplianceWarnings from '@/components/ComplianceWarnings.vue';
import InputError from '@/components/InputError.vue';
import SendConfirmDialog from '@/components/newsletters/SendConfirmDialog.vue';
import TargetingForm from '@/components/newsletters/TargetingForm.vue';
import ProjectMultiSelect from '@/components/projects/ProjectMultiSelect.vue';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import TemplatePickerModal from '@/components/templates/TemplatePickerModal.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useAudienceCount } from '@/composables/useAudienceCount';
import { matchesGlobalDefault } from '@/composables/useGlobalDefaultHint';
import { useTemplateApply, type EmailTemplateSummary } from '@/composables/useTemplateApply';
import { toast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { needsUnsubscribeWarning } from '@/lib/compliance';
import {
    filterToSentence,
    type FilterSentenceContext,
} from '@/lib/filterSentence';
import { extractSlot, hydrateTemplate } from '@/lib/template-slot';
import { formatTimezoneLabel, formatUtcDateTimeForInput } from '@/lib/timezone';
import newslettersRoutes, {
    create as newsletterCreate,
    index as newslettersIndex,
} from '@/routes/newsletters';
import { show as newsletterPreviewShow } from '@/routes/newsletters/preview';
import { show as newsletterScheduleShow } from '@/routes/newsletters/schedule';
import {
    show as newsletterVariablesShow,
    update as newsletterVariablesUpdate,
} from '@/routes/newsletters/variables';
import type { AppPageProps, BreadcrumbItem } from '@/types';
import type {
    AttributeOperator,
    AttributeValue,
    FilterCategory,
    FilterConnector,
    FilterMode,
    NewsletterFilter,
    Segment,
} from '@/types/newsletters';
import { ATTRIBUTE_OPERATORS } from '@/types/newsletters';

interface MailList {
    id: number;
    name: string;
}

interface EmailIdentity {
    id: number;
    email: string;
    name?: string | null;
}

interface TagOption {
    id: number;
    name: string;
}

type FilterForm = NewsletterFilter;

/**
 * Loosely-typed shape of a server-stored filter before normalization.
 * Covers all 7 filter categories; the backend FilterPreparationService
 * already cleans these, so most fields pass through unchanged.
 */
type RawFilter = {
    connector?: FilterConnector;
    category?: FilterCategory;
    mode?: FilterMode;
    operator?: string;
    field?: string;
    values?: unknown;
};

type StepKey = 'content' | 'variables' | 'filters' | 'preview' | 'schedule';

interface DraftNewsletter {
    id: number;
    title?: string | null;
    status: string;
    email_identity_id?: number | null;
    subject?: string | null;
    preview_text?: string | null;
    html_content?: string | null;
    email_template_id?: number | null;
    template_slot_content?: string | null;
    email_template?: EmailTemplateSummary | null;
    ab_test_enabled?: boolean | null;
    subject_b?: string | null;
    preview_text_b?: string | null;
    html_content_b?: string | null;
    template_slot_content_b?: string | null;
    filters?: RawFilter[] | null;
    segment_id?: number | null;
    email_identity?: EmailIdentity | null;
    scheduled_at?: string | null;
    project_ids?: number[];
}

interface TopicOption {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

const props = defineProps<{
    lists: MailList[];
    segments: Segment[];
    emailIdentities: EmailIdentity[];
    tags: TagOption[];
    topics?: TopicOption[];
    contactAttributes: string[];
    filterNewsletters?: { id: number; title: string }[];
    filterCountries?: string[];
    filterTimezones?: string[];
    projects?: { id: number; name: string }[];
    draftNewsletter: DraftNewsletter | null;
    defaultTemplate?: EmailTemplateSummary | null;
    initialStep?: StepKey;
    detectedVariables?: string[];
    variableDefaults?: Record<string, string>;
    globalVariableDefaults?: Record<string, string>;
}>();

const STEP_FILTERS = 2;
const STEP_SCHEDULE = 4;

const steps: Array<{ key: StepKey; title: string; description: string }> = [
    { key: 'content', title: 'Content', description: 'Write your message' },
    {
        key: 'variables',
        title: 'Variables',
        description: 'Set default values for template variables',
    },
    { key: 'filters', title: 'Audience', description: 'Target the right people' },
    { key: 'preview', title: 'Preview', description: 'Review personalization' },
    { key: 'schedule', title: 'Schedule', description: 'Choose when to send' },
];

const breadcrumbs = computed((): BreadcrumbItem[] => [
    {
        title: 'Newsletters',
        href: newslettersIndex().url,
    },
    {
        title: 'Create newsletter',
        href: props.draftNewsletter
            ? newslettersRoutes.content.show(props.draftNewsletter.id).url
            : newsletterCreate().url,
    },
]);

const findStepIndex = (key?: StepKey): number => {
    const index = steps.findIndex((step) => step.key === key);
    return index === -1 ? 0 : index;
};

const currentStep = ref(findStepIndex(props.initialStep));
const currentDraft = ref<DraftNewsletter | null>(props.draftNewsletter ?? null);
const page = usePage<AppPageProps>();
const userTimezone = computed(() => page.props.auth.timezone ?? 'UTC');
const timezoneLabel = computed(() => formatTimezoneLabel(userTimezone.value));

const hasEmailIdentities = computed(() => props.emailIdentities.length > 0);

/** Variables step is only relevant once the content references {{ variables }}. */
const hasDetectedVariables = computed(
    () => (props.detectedVariables?.length ?? 0) > 0,
);

/** Steps shown in the stepper — the Variables step hides until variables exist. */
const visibleSteps = computed(() =>
    steps
        .map((step, index) => ({ ...step, index }))
        .filter((step) => step.key !== 'variables' || hasDetectedVariables.value),
);

const isTemplatePickerOpen = ref(false);

const openTemplatePicker = () => {
    isTemplatePickerOpen.value = true;
};

const contentForm = useForm({
    title: '',
    subject: '',
    preview_text: '',
    html_content: '',
    email_template_id: null as number | null,
    ab_test_enabled: false,
    subject_b: '',
    preview_text_b: '',
    html_content_b: '',
});

const {
    appliedTemplate,
    applyTemplate,
    removeTemplate,
} = useTemplateApply(contentForm);

const handleTemplateSelect = (template: EmailTemplateSummary) => {
    applyTemplate(template);
};

const handleEditorUpdate = (html: string) => {
    contentForm.html_content = html;
};

const handleEditorUpdateB = (html: string) => {
    contentForm.html_content_b = html;
};

// Composes the editable slot HTML inside its template wrapper (or returns the
// slot as-is when no template is applied). Used at submit time so the editor
// only ever holds the slot content while the server still receives the full
// composed HTML it expects.
const composeForSubmit = (
    template: EmailTemplateSummary | null,
    slot: string,
): string => (template ? hydrateTemplate(template.html_content, slot) : slot);

/**
 * The variants whose composed HTML has no `{{ unsubscribe_url }}`. This is a
 * warning only. The server does not block the send for it.
 */
const partsWithoutUnsubscribeLink = computed<string[]>(() => {
    const htmlA = composeForSubmit(appliedTemplate.value, contentForm.html_content);

    if (!contentForm.ab_test_enabled) {
        return needsUnsubscribeWarning(htmlA) ? ['This content'] : [];
    }

    const htmlB = composeForSubmit(appliedTemplate.value, contentForm.html_content_b);

    return [
        ...(needsUnsubscribeWarning(htmlA) ? ['Variant A'] : []),
        ...(needsUnsubscribeWarning(htmlB) ? ['Variant B'] : []),
    ];
});

const contentStepUrl = computed(() =>
    currentDraft.value
        ? newslettersRoutes.content.show(currentDraft.value.id).url
        : null,
);

const variablesForm = useForm<{
    content_variables: Record<string, string>;
}>({
    content_variables: {},
});

const isFromGlobalDefault = (variable: string): boolean =>
    matchesGlobalDefault(
        props.globalVariableDefaults,
        variable,
        variablesForm.content_variables[variable],
    );

const filtersForm = useForm<{
    filters: FilterForm[];
    segment_id: number | null;
}>({
    filters: [],
    segment_id: null,
});

/** Effective filters used for the preview count: segment wins when bound. */
const previewFilters = computed<FilterForm[]>(() => {
    if (filtersForm.segment_id != null) {
        const segment = props.segments.find(
            (candidate) => candidate.id === filtersForm.segment_id,
        );

        return (segment?.filters ?? []) as FilterForm[];
    }

    return filtersForm.filters;
});

const scheduleForm = useForm<{
    action: 'draft' | 'schedule' | 'send_now';
    scheduled_for: string;
    email_identity_id: string;
    project_ids: number[];
}>({
    action: 'draft',
    scheduled_for: '',
    email_identity_id: '',
    project_ids: [],
});

const canSubmitSchedule = computed(
    () => Boolean(scheduleForm.email_identity_id) && hasEmailIdentities.value,
);

/** Identity already persisted on the draft, if any. */
const savedIdentityId = computed(
    () =>
        currentDraft.value?.email_identity?.id ??
        currentDraft.value?.email_identity_id ??
        null,
);

/** Test mail works as long as a sender is selected here OR saved on the draft. */
const canSendTestMail = computed(
    () => Boolean(scheduleForm.email_identity_id) || Boolean(savedIdentityId.value),
);

const filterSentenceContext = computed((): FilterSentenceContext => ({
    lists: props.lists.map((list) => ({ id: list.id, name: list.name })),
    topics: (props.topics ?? []).map((topic) => ({
        id: topic.id,
        name: topic.name,
        list_name: topic.list_name,
    })),
    newsletters: (props.filterNewsletters ?? []).map((newsletter) => ({
        id: newsletter.id,
        title: newsletter.title,
    })),
}));

const selectedIdentityLabel = computed(() => {
    const id = scheduleForm.email_identity_id || savedIdentityId.value;
    if (!id) {
        return null;
    }
    const identity = props.emailIdentities.find(
        (candidate) => String(candidate.id) === String(id),
    );
    if (!identity) {
        return null;
    }
    return `${identity.name ?? identity.email} <${identity.email}>`;
});

/** Backfills missing `operator` on legacy attribute rules with `'equals'`. */
const normalizeAttributeValues = (raw: unknown): AttributeValue[] => {
    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.map((value): AttributeValue => {
        if (typeof value === 'string') {
            return { key: value, operator: 'equals', value: '' };
        }

        if (typeof value === 'number') {
            return { key: String(value), operator: 'equals', value: '' };
        }

        const record = (value ?? {}) as Record<string, unknown>;
        const operator = record.operator as AttributeOperator | undefined;

        return {
            key: typeof record.key === 'string' ? record.key : '',
            operator:
                operator && ATTRIBUTE_OPERATORS.includes(operator)
                    ? operator
                    : 'equals',
            value: typeof record.value === 'string' ? record.value : '',
        };
    });
};

/**
 * Server-stored filters arrive already cleaned by the backend
 * FilterPreparationService. New-category filters (date / engagement /
 * location) pass straight through; only legacy attribute rules need
 * `operator` backfilled to `'equals'`.
 */
const normalizeFilters = (
    rawFilters: RawFilter[] | null | undefined,
): FilterForm[] => {
    if (!rawFilters || rawFilters.length === 0) {
        return [];
    }

    return rawFilters.map((filter): FilterForm => {
        const connector: FilterConnector = filter.connector ?? 'and';
        const category: FilterCategory = filter.category ?? 'attributes';

        if (category === 'attributes') {
            return {
                connector,
                category: 'attributes',
                mode: (filter.mode ?? 'all') as FilterMode,
                values: normalizeAttributeValues(filter.values),
            };
        }

        return {
            ...(filter as unknown as FilterForm),
            connector,
        };
    });
};

watch(
    () => props.draftNewsletter,
    (draft) => {
        currentDraft.value = draft ?? null;
    },
);

// Resync the forms only when the user opens another draft or another wizard
// step. A reload of the same step (test mail, title, projects) keeps the
// unsaved edits. A save redirects to the next step, so the next step gets the
// values that the server saved, for example new variable defaults.
watch(
    [() => props.draftNewsletter?.id, () => props.initialStep],
    () => {
        const draft = props.draftNewsletter;

        if (!draft) {
            contentForm.reset();
            filtersForm.filters = [];
            filtersForm.segment_id = null;
            scheduleForm.scheduled_for = '';
            scheduleForm.action = 'draft';
            scheduleForm.email_identity_id = '';
            scheduleForm.project_ids = [];
            currentStep.value = 0;

            return;
        }

        const identityId =
            draft.email_identity?.id ?? draft.email_identity_id ?? '';
        scheduleForm.email_identity_id = identityId ? String(identityId) : '';
        contentForm.title = draft.title ?? '';
        contentForm.subject = draft.subject ?? '';
        contentForm.preview_text = draft.preview_text ?? '';

        const rawHtml = draft.html_content ?? '';
        const rawHtmlB = draft.html_content_b ?? '';
        const draftTemplateId = draft.email_template_id ?? null;

        contentForm.html_content = draftTemplateId
            ? (draft.template_slot_content ?? extractSlot(rawHtml).slotContent ?? rawHtml)
            : (extractSlot(rawHtml).slotContent || rawHtml);
        contentForm.email_template_id = draftTemplateId;
        contentForm.ab_test_enabled = draft.ab_test_enabled ?? false;
        contentForm.subject_b = draft.subject_b ?? '';
        contentForm.preview_text_b = draft.preview_text_b ?? '';
        contentForm.html_content_b = draftTemplateId
            ? (draft.template_slot_content_b ?? extractSlot(rawHtmlB).slotContent ?? rawHtmlB)
            : (extractSlot(rawHtmlB).slotContent || rawHtmlB);

        const draftHasContent = Boolean(
            (draft.html_content ?? '').trim() || (draft.subject ?? '').trim(),
        );

        if (
            !contentForm.email_template_id &&
            !draftHasContent &&
            props.defaultTemplate
        ) {
            applyTemplate(props.defaultTemplate);
        } else if (contentForm.email_template_id && draft.email_template) {
            appliedTemplate.value = draft.email_template;
        } else if (contentForm.email_template_id && props.defaultTemplate && contentForm.email_template_id === props.defaultTemplate.id) {
            appliedTemplate.value = props.defaultTemplate;
        }

        variablesForm.content_variables = props.variableDefaults ?? {};

        filtersForm.filters = normalizeFilters(draft.filters);
        filtersForm.segment_id = draft.segment_id ?? null;

        scheduleForm.scheduled_for = draft.scheduled_at
            ? formatUtcDateTimeForInput(draft.scheduled_at, userTimezone.value)
            : '';
        scheduleForm.project_ids = draft.project_ids ?? [];

        // The saved values become the defaults, so isDirty means unsaved edits.
        contentForm.defaults();
        variablesForm.defaults();
        filtersForm.defaults();
        scheduleForm.defaults();
    },
    { immediate: true },
);

watch(
    () => props.initialStep,
    (step) => {
        currentStep.value = findStepIndex(step);
    },
);

const getStepUrl = (index: number): string => {
    if (!props.draftNewsletter) return '';
    const id = props.draftNewsletter.id;
    const urls = [
        newslettersRoutes.content.show(id).url,
        newsletterVariablesShow(id).url,
        newslettersRoutes.filters.show(id).url,
        newsletterPreviewShow(id).url,
        newsletterScheduleShow(id).url,
    ];
    return urls[index] ?? '';
};

const saveContent = (): void => {
    if (!currentDraft.value) {
        return;
    }

    contentForm
        .transform((data) => ({
            ...data,
            html_content: composeForSubmit(appliedTemplate.value, data.html_content),
            html_content_b: data.ab_test_enabled
                ? composeForSubmit(appliedTemplate.value, data.html_content_b)
                : data.html_content_b,
        }))
        .patch(newslettersRoutes.content.update(currentDraft.value.id).url, {
            preserveScroll: true,
            onError: toastStatusError,
        });
};

/**
 * A `status` error has no field: the newsletter started to send.
 */
const toastStatusError = (errors: Record<string, string>): void => {
    if (errors.status) {
        toast.error(errors.status);
    }
};

const saveVariables = (): void => {
    if (!currentDraft.value) {
        return;
    }

    variablesForm.patch(newsletterVariablesUpdate(currentDraft.value.id).url, {
        preserveScroll: true,
    });
};

const goToPrevious = (): void => {
    if (currentStep.value === 0) {
        return;
    }

    setStep(currentStep.value - 1);
};

const showEmptyFiltersConfirm = ref(false);
const sendDialogOpen = ref(false);
const sendDialogAction = ref<'send_now' | 'schedule'>('send_now');

const saveFilters = (): void => {
    if (!currentDraft.value) {
        return;
    }

    // Targeting is exclusive: when a segment is selected, never send the
    // (preserved) custom filters — the segment defines the audience.
    filtersForm
        .transform((data) => ({
            ...data,
            filters: data.segment_id != null ? [] : data.filters,
        }))
        .patch(newslettersRoutes.filters.update(currentDraft.value.id).url, {
            preserveScroll: true,
            onError: toastStatusError,
        });
};

const handleFiltersSave = (): void => {
    if (filtersForm.segment_id == null && filtersForm.filters.length === 0) {
        showEmptyFiltersConfirm.value = true;
        return;
    }

    saveFilters();
};

const confirmEmptyFilters = (): void => {
    showEmptyFiltersConfirm.value = false;
    saveFilters();
};

const continueToSchedule = (): void => {
    if (!currentDraft.value) {
        return;
    }

    router.visit(newsletterScheduleShow(currentDraft.value.id).url);
};

const askToSend = (action: 'send_now' | 'schedule'): void => {
    if (scheduleForm.processing || !canSubmitSchedule.value) {
        return;
    }

    sendDialogAction.value = action;
    sendDialogOpen.value = true;
};

const confirmSend = (): void => {
    if (scheduleForm.processing) {
        return;
    }

    sendDialogOpen.value = false;
    submitSchedule(sendDialogAction.value);
};

const submitSchedule = (action: 'draft' | 'schedule' | 'send_now'): void => {
    if (!currentDraft.value) {
        return;
    }

    scheduleForm.action = action;

    scheduleForm
        .transform((data) => ({
            ...data,
            scheduled_for:
                action === 'schedule' && data.scheduled_for
                    ? data.scheduled_for
                    : null,
            email_identity_id: data.email_identity_id
                ? Number(data.email_identity_id)
                : data.email_identity_id,
        }))
        .post(newslettersRoutes.schedule.post(currentDraft.value.id).url, {
            preserveScroll: true,
            onError: (errors) => {
                const message = sendBlockingErrorKeys
                    .map((key) => errors[key])
                    .find(Boolean);

                if (message) {
                    toast.error(message);
                }
            },
        });
};

/**
 * Schedule-step errors with no inline field.
 */
const sendBlockingErrorKeys = [
    'subject',
    'html_content',
    'subject_b',
    'html_content_b',
    'ses_account',
    'ses_permissions',
    'status',
] as const;

const previewVariant = ref<'a' | 'b'>('a');

const isSendingTestMail = ref(false);

const sendTestMail = (): void => {
    if (!currentDraft.value || !canSendTestMail.value) {
        return;
    }

    isSendingTestMail.value = true;

    router.post(
        NewsletterTestMailController(currentDraft.value.id).url,
        { email_identity_id: scheduleForm.email_identity_id || savedIdentityId.value },
        {
            preserveScroll: true,
            // Reload only the flash message: a fresh draft prop must not
            // touch the schedule form. The global flash toast reports the result.
            only: ['flash'],
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Failed to send test email.');
            },
            onFinish: () => {
                isSendingTestMail.value = false;
            },
        },
    );
};

/** The form of each step, in step order. The preview step has no form. */
const stepForms = [contentForm, variablesForm, filtersForm, null, scheduleForm];
const pendingStepUrl = ref<string | null>(null);

const setStep = (index: number): void => {
    const url = getStepUrl(index);
    if (!url) {
        currentStep.value = index;
        return;
    }

    if (index !== currentStep.value && stepForms[currentStep.value]?.isDirty) {
        pendingStepUrl.value = url;
        return;
    }

    router.visit(url);
};

const leaveStep = (): void => {
    const url = pendingStepUrl.value;
    pendingStepUrl.value = null;
    if (url) {
        router.visit(url);
    }
};

const {
    count: recipientCount,
    loading: recipientCountLoading,
    fetch: fetchRecipientCount,
    schedule: scheduleRecipientCountFetch,
} = useAudienceCount();
let recipientCountFetchEnabled = false;

onMounted(() => {
    recipientCountFetchEnabled = true;
});

watch(
    previewFilters,
    (filters) => {
        if (!recipientCountFetchEnabled || currentStep.value !== STEP_FILTERS) {
            return;
        }

        scheduleRecipientCountFetch(filters);
    },
    { deep: true },
);

watch(
    currentStep,
    (step) => {
        if (step === STEP_FILTERS || step === STEP_SCHEDULE) {
            fetchRecipientCount(previewFilters.value);
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create newsletter" />

        <section class="space-y-5">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold" style="color: var(--bm-text-1);">
                    Create newsletter
                </h1>
                <p class="mt-1 text-sm" style="color: var(--bm-text-3);">
                    Move through each step to go from a draft to a scheduled send.
                </p>
            </div>

            <!-- Stepper -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-2"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
            >
                <div class="flex flex-wrap items-center gap-1">
                    <template
                        v-for="(step, position) in visibleSteps"
                        :key="step.key"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-[var(--bm-radius)] px-3 py-1.5 transition-colors"
                            :style="{
                                backgroundColor:
                                    currentStep === step.index
                                        ? 'var(--bm-accent-bright-dim)'
                                        : 'transparent',
                            }"
                            @click="setStep(step.index)"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold tabular-nums"
                                :style="{
                                    fontFamily: 'var(--bm-font-mono)',
                                    backgroundColor:
                                        currentStep > step.index
                                            ? 'var(--bm-accent)'
                                            : currentStep === step.index
                                              ? 'var(--bm-accent)'
                                              : 'var(--bm-elevated)',
                                    color:
                                        currentStep >= step.index
                                            ? 'var(--bm-accent-text)'
                                            : 'var(--bm-text-3)',
                                }"
                            >
                                <PhCheck
                                    v-if="currentStep > step.index"
                                    :size="11"
                                    weight="bold"
                                />
                                <template v-else>{{ position + 1 }}</template>
                            </span>
                            <span
                                class="text-sm font-medium"
                                :style="{
                                    color:
                                        currentStep === step.index
                                            ? 'var(--bm-accent)'
                                            : 'var(--bm-text-3)',
                                }"
                            >
                                {{ step.title }}
                            </span>
                        </button>
                        <span
                            v-if="position < visibleSteps.length - 1"
                            class="h-px w-4"
                            style="background-color: var(--bm-border);"
                        ></span>
                    </template>
                </div>
            </div>

            <!-- Step content -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-6"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
            >
                <div class="mb-5 flex items-baseline gap-2">
                    <h2 class="text-sm font-semibold" style="color: var(--bm-text-1);">
                        {{ steps[currentStep].title }}
                    </h2>
                    <p class="text-sm" style="color: var(--bm-text-3);">
                        {{ steps[currentStep].description }}
                    </p>
                </div>

                <!-- Step 1 — Content -->
                <form
                    v-if="currentStep === 0"
                    class="space-y-5"
                    @submit.prevent="saveContent"
                >
                    <div class="grid gap-1.5">
                        <label
                            for="title"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Title
                            <span style="color: var(--bm-text-3);">— internal use only</span>
                        </label>
                        <input
                            id="title"
                            v-model="contentForm.title"
                            type="text"
                            placeholder="e.g. April product update"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="contentForm.processing"
                            required
                        />
                        <InputError :message="contentForm.errors.title" />
                    </div>

                    <label
                        class="flex cursor-pointer items-start gap-2.5 rounded-[var(--bm-radius)] border px-3.5 py-3"
                        style="background-color: var(--bm-base); border-color: var(--bm-border);"
                    >
                        <input
                            v-model="contentForm.ab_test_enabled"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0"
                            :disabled="contentForm.processing"
                        />
                        <span class="text-sm">
                            <span class="font-medium" style="color: var(--bm-text-1);">
                                Enable A/B testing
                            </span>
                            <span style="color: var(--bm-text-3);">
                                — test two variants (subject + body) on a 50/50 split
                            </span>
                        </span>
                    </label>

                    <div class="grid gap-1.5">
                        <label
                            for="subject"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            {{ contentForm.ab_test_enabled ? 'Variant A — Subject' : 'Subject' }}
                        </label>
                        <input
                            id="subject"
                            v-model="contentForm.subject"
                            type="text"
                            placeholder="Monthly update"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="contentForm.processing"
                            required
                        />
                        <InputError :message="contentForm.errors.subject" />
                    </div>

                    <div class="grid gap-1.5">
                        <label
                            for="preview_text"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            {{ contentForm.ab_test_enabled ? 'Variant A — Preview text' : 'Preview text' }}
                            <span class="font-normal" style="color: var(--bm-text-3);">
                                — preheader shown next to subject in inbox (optional)
                            </span>
                        </label>
                        <input
                            id="preview_text"
                            v-model="contentForm.preview_text"
                            type="text"
                            maxlength="255"
                            placeholder="A short teaser inbox clients show next to the subject"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="contentForm.processing"
                        />
                        <InputError :message="contentForm.errors.preview_text" />
                    </div>

                    <div class="grid gap-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium" style="color: var(--bm-text-1);">
                                {{ contentForm.ab_test_enabled ? 'Variant A — Content' : 'Content' }}
                            </span>
                            <div class="flex items-center gap-3 text-xs font-medium">
                                <button
                                    type="button"
                                    class="transition hover:underline"
                                    style="color: var(--bm-accent);"
                                    @click="openTemplatePicker()"
                                >
                                    {{ appliedTemplate ? appliedTemplate.name : 'Use template' }}
                                </button>
                                <button
                                    v-if="appliedTemplate"
                                    type="button"
                                    class="transition hover:underline"
                                    style="color: var(--bm-error);"
                                    @click="removeTemplate"
                                >
                                    Remove template
                                </button>
                            </div>
                        </div>
                        <TiptapEditor
                            :model-value="contentForm.html_content"
                            :template="appliedTemplate"
                            :aria-label="contentForm.ab_test_enabled ? 'Variant A content' : 'Content'"
                            placeholder="Write your newsletter content..."
                            :disabled="contentForm.processing"
                            @update:model-value="handleEditorUpdate"
                        />
                        <InputError :message="contentForm.errors.html_content" />
                    </div>

                    <template v-if="contentForm.ab_test_enabled">
                        <div class="grid gap-1.5">
                            <label
                                for="subject_b"
                                class="text-sm font-medium"
                                style="color: var(--bm-text-1);"
                            >
                                Variant B — Subject
                            </label>
                            <input
                                id="subject_b"
                                v-model="contentForm.subject_b"
                                type="text"
                                placeholder="Alternative subject line"
                                class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                                :disabled="contentForm.processing"
                                required
                            />
                            <InputError :message="contentForm.errors.subject_b" />
                        </div>

                        <div class="grid gap-1.5">
                            <label
                                for="preview_text_b"
                                class="text-sm font-medium"
                                style="color: var(--bm-text-1);"
                            >
                                Variant B — Preview text
                                <span class="font-normal" style="color: var(--bm-text-3);">
                                    — preheader shown next to subject (optional)
                                </span>
                            </label>
                            <input
                                id="preview_text_b"
                                v-model="contentForm.preview_text_b"
                                type="text"
                                maxlength="255"
                                placeholder="Alternative preview text"
                                class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                                :disabled="contentForm.processing"
                            />
                            <InputError :message="contentForm.errors.preview_text_b" />
                        </div>

                        <div class="grid gap-1.5">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium" style="color: var(--bm-text-1);">
                                    Variant B — Content
                                </span>
                                <span
                                    v-if="appliedTemplate"
                                    class="text-xs"
                                    style="font-family: var(--bm-font-mono); color: var(--bm-text-3);"
                                >
                                    Uses same template
                                </span>
                            </div>
                            <TiptapEditor
                                :model-value="contentForm.html_content_b"
                                :template="appliedTemplate"
                                aria-label="Variant B content"
                                placeholder="Write your variant B content..."
                                :disabled="contentForm.processing"
                                @update:model-value="handleEditorUpdateB"
                            />
                            <InputError :message="contentForm.errors.html_content_b" />
                        </div>
                    </template>

                    <ComplianceWarnings
                        :without-unsubscribe-link="partsWithoutUnsubscribeLink"
                    />

                    <div class="flex items-center gap-3 pt-1">
                        <Button type="submit" :disabled="contentForm.processing">
                            {{
                                contentForm.processing
                                    ? 'Saving…'
                                    : currentDraft
                                      ? 'Save and continue'
                                      : 'Save & continue'
                            }}
                        </Button>
                    </div>
                </form>

                <!-- Step 2 — Variables -->
                <div v-else-if="currentStep === 1" class="space-y-5">
                    <div v-if="hasDetectedVariables">
                        <p class="text-sm" style="color: var(--bm-text-3);">
                            We detected these template variables in your content.
                            Set default values for when a contact is missing data.
                        </p>
                        <div class="mt-4 space-y-3">
                            <div
                                v-for="variable in props.detectedVariables"
                                :key="variable"
                                class="grid gap-1.5"
                            >
                                <label
                                    :for="`var_${variable}`"
                                    class="text-sm font-medium"
                                    style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                                >
                                    {{ variable }}
                                </label>
                                <input
                                    :id="`var_${variable}`"
                                    v-model="variablesForm.content_variables[variable]"
                                    type="text"
                                    :placeholder="`Default value for ${variable}`"
                                    class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                                    :disabled="variablesForm.processing"
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
                    </div>
                    <div
                        v-else
                        class="rounded-[var(--bm-radius)] border border-dashed px-4 py-6 text-center text-sm"
                        style="border-color: var(--bm-border); color: var(--bm-text-3);"
                    >
                        No template variables were detected in your content.
                        You can continue to the next step.
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <Button type="button" variant="outline" @click="goToPrevious">
                            Back
                        </Button>
                        <Button
                            type="button"
                            :disabled="variablesForm.processing"
                            @click="saveVariables"
                        >
                            {{ variablesForm.processing ? 'Saving…' : 'Save & continue' }}
                        </Button>
                    </div>
                </div>

                <!-- Step 3 — Audience -->
                <div v-else-if="currentStep === 2" class="space-y-4">
                    <TargetingForm
                        v-model:filters="filtersForm.filters"
                        v-model:segment-id="filtersForm.segment_id"
                        :segments="props.segments"
                        :lists="props.lists"
                        :tags="props.tags"
                        :topics="props.topics ?? []"
                        :attribute-keys="props.contactAttributes"
                        :filter-newsletters="props.filterNewsletters ?? []"
                        :filter-countries="props.filterCountries ?? []"
                        :filter-timezones="props.filterTimezones ?? []"
                        :processing="filtersForm.processing"
                        :can-edit="Boolean(currentDraft)"
                        :filters-error="filtersForm.errors.filters"
                        :segment-error="filtersForm.errors.segment_id"
                        :require-list-filter="false"
                        @back="goToPrevious"
                        @save="handleFiltersSave"
                    />

                    <div
                        class="rounded-[var(--bm-radius)] border px-4 py-3 text-sm"
                        style="background-color: var(--bm-base); border-color: var(--bm-border);"
                    >
                        <span
                            v-if="recipientCountLoading"
                            style="color: var(--bm-text-3);"
                        >
                            Calculating recipients…
                        </span>
                        <span v-else-if="recipientCount !== null" style="color: var(--bm-text-2);">
                            This newsletter will be sent to
                            <span
                                class="font-semibold tabular-nums"
                                style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                            >{{ recipientCount.toLocaleString() }}</span>
                            {{ recipientCount === 1 ? 'contact' : 'contacts' }}.
                        </span>
                    </div>
                </div>

                <!-- Step 4 — Preview -->
                <div v-else-if="currentStep === 3" class="space-y-5">
                    <div
                        v-if="currentDraft"
                        class="space-y-4"
                    >
                        <div
                            v-if="currentDraft.ab_test_enabled"
                            class="flex gap-1"
                        >
                            <button
                                v-for="variant in (['a', 'b'] as const)"
                                :key="variant"
                                type="button"
                                class="rounded-[var(--bm-radius)] px-3 py-1.5 text-sm font-medium transition-colors"
                                :style="{
                                    backgroundColor:
                                        previewVariant === variant
                                            ? 'var(--bm-accent-bright-dim)'
                                            : 'transparent',
                                    color:
                                        previewVariant === variant
                                            ? 'var(--bm-accent)'
                                            : 'var(--bm-text-3)',
                                }"
                                @click="previewVariant = variant"
                            >
                                Variant {{ variant.toUpperCase() }}
                            </button>
                        </div>

                        <div
                            class="rounded-[var(--bm-radius)] border px-4 py-3"
                            style="background-color: var(--bm-base); border-color: var(--bm-border);"
                        >
                            <p
                                class="text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Subject
                            </p>
                            <p class="mt-1 text-sm" style="color: var(--bm-text-1);">
                                {{
                                    currentDraft.ab_test_enabled && previewVariant === 'b'
                                        ? (currentDraft.subject_b ?? '—')
                                        : (currentDraft.subject ?? '—')
                                }}
                            </p>
                        </div>

                        <div
                            class="rounded-[var(--bm-radius)] border bg-white"
                            style="border-color: var(--bm-border); overflow: hidden;"
                        >
                            <HtmlPreview
                                :html="
                                    (currentDraft.ab_test_enabled && previewVariant === 'b'
                                        ? (currentDraft.html_content_b ?? '')
                                        : (currentDraft.html_content ?? '')) ||
                                    '<p style=\'color:#6b7280\'>Add content to see a preview.</p>'
                                "
                            />
                        </div>
                    </div>
                    <div
                        v-else
                        class="rounded-[var(--bm-radius)] border border-dashed px-4 py-6 text-center text-sm"
                        style="border-color: var(--bm-border); color: var(--bm-text-3);"
                    >
                        Save your draft to see a preview.
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <Button type="button" variant="outline" @click="goToPrevious">
                            Back
                        </Button>
                        <Button
                            type="button"
                            :disabled="!currentDraft"
                            @click="continueToSchedule"
                        >
                            Continue to schedule
                        </Button>
                    </div>
                </div>

                <!-- Step 5 — Schedule -->
                <div v-else class="space-y-5">
                    <ComplianceWarnings
                        :without-unsubscribe-link="partsWithoutUnsubscribeLink"
                        :content-href="contentStepUrl"
                    />

                    <!-- Audience summary -->
                    <div
                        class="rounded-[var(--bm-radius)] border"
                        style="background-color: var(--bm-base); border-color: var(--bm-border); overflow: hidden;"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b px-4 py-2.5"
                            style="border-color: var(--bm-border);"
                        >
                            <p
                                class="text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Audience
                            </p>
                            <span
                                v-if="recipientCountLoading"
                                class="text-xs"
                                style="color: var(--bm-text-3);"
                            >
                                Calculating…
                            </span>
                            <span
                                v-else-if="recipientCount !== null"
                                class="text-xs tabular-nums"
                                style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                            >
                                {{ recipientCount.toLocaleString() }}
                                {{ recipientCount === 1 ? 'contact' : 'contacts' }}
                            </span>
                        </div>
                        <div class="space-y-2 px-4 py-3">
                            <template v-if="previewFilters.length">
                                <div
                                    v-for="(filter, index) in previewFilters"
                                    :key="index"
                                    class="rounded-[var(--bm-radius-sm)] border px-3 py-2 text-sm"
                                    style="background-color: var(--bm-surface); border-color: var(--bm-border); color: var(--bm-text-2);"
                                >
                                    {{ filterToSentence(filter, index, filterSentenceContext) }}
                                </div>
                            </template>
                            <p v-else class="text-sm" style="color: var(--bm-text-3);">
                                No filters. Sends to all active contacts.
                            </p>
                        </div>
                    </div>

                    <!-- Send from -->
                    <div class="grid gap-1.5">
                        <label
                            for="schedule_identity"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Send from
                        </label>
                        <select
                            id="schedule_identity"
                            v-model="scheduleForm.email_identity_id"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="scheduleForm.processing || !hasEmailIdentities"
                            required
                        >
                            <option value="" disabled>Select sender</option>
                            <option
                                v-for="identity in props.emailIdentities"
                                :key="identity.id"
                                :value="String(identity.id)"
                            >
                                {{ identity.name ?? identity.email }} &lt;{{ identity.email }}&gt;
                            </option>
                        </select>
                        <InputError :message="scheduleForm.errors.email_identity_id" />
                        <p
                            v-if="!hasEmailIdentities"
                            class="text-xs"
                            style="color: var(--bm-text-3);"
                        >
                            Add an email identity first to send newsletters.
                        </p>
                    </div>

                    <!-- Projects -->
                    <div v-if="(props.projects ?? []).length > 0" class="grid gap-1.5">
                        <span
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Projects
                        </span>
                        <ProjectMultiSelect
                            v-model="scheduleForm.project_ids"
                            :options="props.projects ?? []"
                        />
                        <InputError :message="scheduleForm.errors.project_ids" />
                    </div>

                    <!-- Schedule for later -->
                    <div
                        class="space-y-3 rounded-[var(--bm-radius)] border p-4"
                        style="background-color: var(--bm-base); border-color: var(--bm-border);"
                    >
                        <label
                            for="scheduled_for"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Schedule for later
                        </label>
                        <input
                            id="scheduled_for"
                            v-model="scheduleForm.scheduled_for"
                            type="datetime-local"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style="background-color: var(--bm-surface); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="scheduleForm.processing"
                        />
                        <InputError :message="scheduleForm.errors.scheduled_for" />
                        <p class="text-xs" style="color: var(--bm-text-3);">
                            Times shown in {{ timezoneLabel }}.
                        </p>
                        <div class="flex flex-wrap gap-3 pt-1">
                            <Button
                                type="button"
                                :disabled="
                                    scheduleForm.processing ||
                                    !scheduleForm.scheduled_for ||
                                    !canSubmitSchedule
                                "
                                @click="askToSend('schedule')"
                            >
                                {{
                                    scheduleForm.processing &&
                                    scheduleForm.action === 'schedule'
                                        ? 'Scheduling…'
                                        : 'Schedule send'
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="scheduleForm.processing || !canSubmitSchedule"
                                @click="askToSend('send_now')"
                            >
                                <PhPaperPlaneTilt :size="14" class="mr-1.5" />
                                {{
                                    scheduleForm.processing &&
                                    scheduleForm.action === 'send_now'
                                        ? 'Sending…'
                                        : 'Send now'
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="scheduleForm.processing || !canSubmitSchedule"
                                @click="submitSchedule('draft')"
                            >
                                {{
                                    scheduleForm.processing &&
                                    scheduleForm.action === 'draft'
                                        ? 'Saving…'
                                        : 'Save draft'
                                }}
                            </Button>
                        </div>
                    </div>

                    <!-- Test email -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--bm-radius)] border px-4 py-3"
                        style="background-color: var(--bm-base); border-color: var(--bm-border);"
                    >
                        <div>
                            <p class="text-sm font-medium" style="color: var(--bm-text-1);">
                                Send yourself a test
                            </p>
                            <p class="text-xs" style="color: var(--bm-text-3);">
                                <template v-if="selectedIdentityLabel">
                                    Delivered from {{ selectedIdentityLabel }}
                                </template>
                                <template v-else>
                                    Pick a sender above to enable test sends.
                                </template>
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                isSendingTestMail ||
                                scheduleForm.processing ||
                                !canSendTestMail
                            "
                            @click="sendTestMail"
                        >
                            {{ isSendingTestMail ? 'Sending…' : 'Send test email' }}
                        </Button>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="scheduleForm.processing"
                            @click="goToPrevious"
                        >
                            Back
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <SendConfirmDialog
            v-model:open="sendDialogOpen"
            :action="sendDialogAction"
            :recipient-count="recipientCount"
            :identity-label="selectedIdentityLabel"
            :scheduled-for="scheduleForm.scheduled_for"
            :timezone-label="timezoneLabel"
            :processing="scheduleForm.processing"
            :without-unsubscribe-link="partsWithoutUnsubscribeLink"
            @confirm="confirmSend"
        />
        <ConfirmDialog
            :is-open="pendingStepUrl !== null"
            title="Leave without saving?"
            description="This step has changes that are not saved. If you leave, you lose them."
            confirm-label="Leave"
            cancel-label="Stay"
            @update:is-open="(open?: boolean) => !open && (pendingStepUrl = null)"
            @confirm="leaveStep"
        />

        <ConfirmDialog
            v-model:is-open="showEmptyFiltersConfirm"
            title="Send to all contacts?"
            description="No filters are set. This newsletter will be sent to all your active contacts. Are you sure you want to continue?"
            confirm-label="Continue without filters"
            cancel-label="Add filters"
            variant="default"
            @confirm="confirmEmptyFilters"
        />
        <TemplatePickerModal
            :is-open="isTemplatePickerOpen"
            @update:is-open="(open: boolean) => (isTemplatePickerOpen = open)"
            @select="handleTemplateSelect"
        />
    </AppLayout>
</template>
