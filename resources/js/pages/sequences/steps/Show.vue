<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PhFunnelSimple, PhPaperPlaneTilt } from '@phosphor-icons/vue';
import { computed, ref, watch } from 'vue';

import SequenceStepTestMailController from '@/actions/App/Http/Controllers/SequenceStepTestMailController';
import ComplianceWarnings from '@/components/ComplianceWarnings.vue';
import InputError from '@/components/InputError.vue';
import FiltersForm from '@/components/newsletters/FiltersForm.vue';
import ConfirmDeleteStepModal from '@/components/sequences/ConfirmDeleteStepModal.vue';
import ConfirmStatusToggleModal from '@/components/sequences/ConfirmStatusToggleModal.vue';
import SequenceStatusBadge from '@/components/sequences/SequenceStatusBadge.vue';
import TemplatePickerModal from '@/components/templates/TemplatePickerModal.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useStatusToggle } from '@/composables/useStatusToggle';
import { useTemplateApply, type EmailTemplateSummary } from '@/composables/useTemplateApply';
import { toast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { needsUnsubscribeWarning } from '@/lib/compliance';
import { extractSlot, hydrateTemplate } from '@/lib/template-slot';
import {
    show as sequenceShow,
    index as sequencesIndex,
} from '@/routes/sequences';
import stepsRoutes from '@/routes/sequences/steps';
import stepContacts from '@/routes/sequences/steps/contacts';
import stepFiltersRoutes from '@/routes/sequences/steps/filters';
import stepStatusRoutes from '@/routes/sequences/steps/status';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterFilter } from '@/types/newsletters';
import type { SequenceStatusOption } from '@/types/sequences';

interface EmailIdentity {
    id: number;
    email: string;
    name?: string | null;
}

interface StepMessage {
    id: number;
    subject: string;
    preview_text?: string | null;
    html_content?: string | null;
    text_content?: string | null;
    email_identity_id?: number | null;
    email_template_id?: number | null;
    template_slot_content?: string | null;
    email_template?: EmailTemplateSummary | null;
    email_identity?: EmailIdentity | null;
}

interface SequenceStep {
    id: number;
    sequence_id: number;
    delay_days: number;
    delay_type: 'days' | 'hours';
    status: string;
    filters: NewsletterFilter[];
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

interface TopicOption {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

interface SequenceSummary {
    id: number;
    name: string;
    status: string;
}

interface StepStats {
    contacts: number;
    unsubscribed: number;
    clicked: number;
}

interface MailMetrics {
    total: number;
    sent: number;
    delivered: number;
    pending: number;
    skipped: number;
    opened: number;
    bounced: number;
    unsubscribed: number;
    complained: number;
    delivery_rate: number | null;
    open_rate: number | null;
    unsubscribe_rate: number | null;
}

const props = defineProps<{
    sequence: SequenceSummary;
    sequenceStatuses: SequenceStatusOption[];
    step: SequenceStep;
    stepIndex: number;
    emailIdentities: EmailIdentity[];
    stepStats: StepStats;
    stepMailMetrics: MailMetrics;
    defaultTemplate?: EmailTemplateSummary | null;
    lists: MailList[];
    tags: TagOption[];
    topics: TopicOption[];
    contactAttributes: string[];
    filterNewsletters: { id: number; title: string }[];
    filterCountries: string[];
    filterTimezones: string[];
    statusImpact?: { enrolled_contacts: number; pending_mails_due_now: number };
}>();

const pageTitle = computed(
    () => `Step ${props.stepIndex + 1} · ${props.sequence.name}`,
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'Sequences',
        href: sequencesIndex().url,
    },
    {
        title: props.sequence.name,
        href: sequenceShow(props.sequence.id).url,
    },
    {
        title: `Step ${props.stepIndex + 1}`,
        href: stepsRoutes.show([props.sequence.id, props.step.id]).url,
    },
]);

const viewMoreUrls = computed(() => {
    const args: [number, number] = [props.sequence.id, props.step.id];

    return {
        contacts: stepContacts.index(args).url,
        unsubscribed: stepContacts.index(args, {
            query: { filter: 'unsubscribed' },
        }).url,
        clicked: stepContacts.index(args, {
            query: { filter: 'clicked' },
        }).url,
    };
});

const stepStatCards = computed(() => {
    const links = viewMoreUrls.value;

    return [
        {
            key: 'contacts',
            label: 'Contacts in this step',
            description: 'Queued or sent',
            value: props.stepStats.contacts,
            href: links.contacts,
        },
        {
            key: 'unsubscribed',
            label: 'Unsubscribed here',
            description: 'Opted out',
            value: props.stepStats.unsubscribed,
            href: links.unsubscribed,
        },
        {
            key: 'clicked',
            label: 'Clicked a link',
            description: 'Engaged contacts',
            value: props.stepStats.clicked,
            href: links.clicked,
        },
    ];
});

const formatNumber = (value: number): string => value.toLocaleString();
const formatRate = (value: number | null | undefined): string =>
    value === null || value === undefined ? '—' : `${value}%`;

const stepMailMetricCards = computed(() => [
    { label: 'Sent', value: formatNumber(props.stepMailMetrics.sent) },
    { label: 'Delivered', value: formatNumber(props.stepMailMetrics.delivered) },
    { label: 'Deliverability', value: formatRate(props.stepMailMetrics.delivery_rate) },
    { label: 'Opened', value: formatNumber(props.stepMailMetrics.opened) },
    { label: 'Open rate', value: formatRate(props.stepMailMetrics.open_rate) },
    { label: 'Pending', value: formatNumber(props.stepMailMetrics.pending) },
    { label: 'Skipped', value: formatNumber(props.stepMailMetrics.skipped) },
    { label: 'Bounced', value: formatNumber(props.stepMailMetrics.bounced) },
    { label: 'Unsubscribed', value: formatNumber(props.stepMailMetrics.unsubscribed) },
    { label: 'Complained', value: formatNumber(props.stepMailMetrics.complained) },
]);

const hasEmailIdentities = computed(() => props.emailIdentities.length > 0);

const isTemplatePickerOpen = ref(false);

const form = useForm({
    delay_days: 0,
    delay_type: 'days' as 'days' | 'hours',
    subject: '',
    preview_text: '',
    content: '',
    email_template_id: null as number | null,
    email_identity_id: '',
});

const {
    appliedTemplate,
    applyTemplate,
    removeTemplate,
    handleTemplateSelect,
} = useTemplateApply(form);

const handleEditorUpdate = (html: string) => {
    form.content = html;
};

/** The edited content has no `{{ unsubscribe_url }}`. A warning only. */
const editedWithoutUnsubscribeLink = computed<string[]>(() => {
    const html = appliedTemplate.value
        ? hydrateTemplate(appliedTemplate.value.html_content, form.content)
        : form.content;

    return needsUnsubscribeWarning(html) ? ['This step'] : [];
});

/** The saved content has no `{{ unsubscribe_url }}`. Shown when the step goes live. */
const savedWithoutUnsubscribeLink = computed<string[]>(() =>
    needsUnsubscribeWarning(
        props.step.message?.html_content,
        props.step.message?.text_content,
    )
        ? ['This step']
        : [],
);

// Resync only when the user opens another step, so a reload of this step
// (status toggle, filters save) does not erase unsaved content edits.
watch(
    () => props.step.id,
    () => {
        const step = props.step;
        form.delay_days = step.delay_days;
        form.delay_type = step.delay_type ?? 'days';
        form.subject = step.message?.subject ?? '';
        form.preview_text = step.message?.preview_text ?? '';
        const rawContent =
            step.message?.html_content ?? step.message?.text_content ?? '';
        const stepTemplateId = step.message?.email_template_id ?? null;
        form.content = stepTemplateId
            ? (step.message?.template_slot_content ?? extractSlot(rawContent).slotContent ?? rawContent)
            : (extractSlot(rawContent).slotContent || rawContent);
        form.email_identity_id = step.message?.email_identity_id
            ? String(step.message.email_identity_id)
            : '';
        form.email_template_id = stepTemplateId;
        appliedTemplate.value = null;
        form.clearErrors();

        const contentEmpty = (step.message?.html_content ?? '').trim() === '';

        if (!form.email_template_id && contentEmpty && props.defaultTemplate) {
            applyTemplate(props.defaultTemplate);
        } else if (form.email_template_id && step.message?.email_template) {
            appliedTemplate.value = step.message.email_template;
        } else if (
            form.email_template_id &&
            props.defaultTemplate &&
            form.email_template_id === props.defaultTemplate.id
        ) {
            appliedTemplate.value = props.defaultTemplate;
        }
    },
    { immediate: true },
);

watch(
    () => props.emailIdentities,
    () => {
        const selectedExists = props.emailIdentities.some(
            (identity) => String(identity.id) === form.email_identity_id,
        );

        if (!selectedExists && props.emailIdentities.length > 0) {
            form.email_identity_id = String(props.emailIdentities[0].id);
        }
    },
    { deep: true },
);

const filtersForm = useForm<{ filters: NewsletterFilter[] }>({
    filters: props.step.filters ?? [],
});

// Resync only when the user opens another step, so a save of the content
// form does not erase filter edits that are not saved yet.
watch(
    () => props.step.id,
    () => {
        filtersForm.filters = props.step.filters ?? [];
        filtersForm.clearErrors();
    },
);

const saveFilters = () => {
    // Reload only the flash message: a fresh `step` prop would reset the
    // content form and erase its unsaved edits.
    filtersForm.patch(stepFiltersRoutes.update([props.sequence.id, props.step.id]).url, {
        preserveScroll: true,
        only: ['flash'],
    });
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        content: appliedTemplate.value
            ? hydrateTemplate(appliedTemplate.value.html_content, data.content)
            : data.content,
    })).patch(stepsRoutes.update([props.sequence.id, props.step.id]).url, {
        preserveScroll: true,
    });
};

const statusToggle = useStatusToggle({
    url: () => stepStatusRoutes.update([props.sequence.id, props.step.id]).url,
    isLive: () => props.step.status === 'live',
});

const confirmingDelete = ref(false);

const confirmDelete = () => {
    confirmingDelete.value = false;

    router.delete(
        stepsRoutes.destroy([props.sequence.id, props.step.id]).url,
        { preserveScroll: true },
    );
};

const isSendingTestMail = ref(false);

const sendTestMail = () => {
    isSendingTestMail.value = true;

    router.post(
        SequenceStepTestMailController([props.sequence.id, props.step.id]).url,
        {},
        {
            preserveScroll: true,
            // Reload only the flash message: a fresh `step` prop must not touch
            // the content form. The global flash toast reports the result.
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

const describeDelay = computed(() => {
    const delay = props.step.delay_days;
    const type = props.step.delay_type ?? 'days';
    const unit = type === 'hours' ? 'hour' : 'day';

    if (props.stepIndex <= 0) {
        if (delay === 0) {
            return 'Sends immediately after a contact is added to the sequence.';
        }

        return `Sends ${delay} ${unit}${delay === 1 ? '' : 's'} after a contact is added to the sequence.`;
    }

    if (delay === 0) {
        return 'Sends immediately after the previous email.';
    }

    return `Sends ${delay} ${unit}${delay === 1 ? '' : 's'} after the previous email.`;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="pageTitle" />

        <section class="mx-auto max-w-3xl space-y-5">
            <!-- Header -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-5"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-[10px] font-semibold uppercase tracking-widest"
                            style="font-family: var(--bm-font-mono); color: var(--bm-text-3);"
                        >
                            Step {{ stepIndex + 1 }}
                        </p>
                        <h1
                            class="mt-1 truncate text-xl font-semibold"
                            style="color: var(--bm-text-1);"
                        >
                            {{ step.message?.subject ?? 'Untitled step' }}
                        </h1>
                        <p class="mt-1 text-sm" style="color: var(--bm-text-3);">
                            {{ describeDelay }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <SequenceStatusBadge
                            :status="step.status"
                            :statuses="props.sequenceStatuses"
                        />
                        <Switch
                            :model-value="step.status === 'live'"
                            aria-label="Live"
                            @update:model-value="statusToggle.request"
                        />
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div
                    v-for="card in stepStatCards"
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

            <!-- Step filters -->
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
                            Step filters
                        </h2>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--bm-text-3);">
                        Contacts who don't match skip this email and continue to the next step.
                    </p>
                </div>
                <div class="p-5">
                    <FiltersForm
                        v-model:filters="filtersForm.filters"
                        :lists="props.lists"
                        :tags="props.tags"
                        :topics="props.topics"
                        :attribute-keys="props.contactAttributes"
                        :filter-newsletters="props.filterNewsletters"
                        :filter-countries="props.filterCountries"
                        :filter-timezones="props.filterTimezones"
                        :processing="filtersForm.processing"
                        :can-edit="true"
                        :require-list-filter="false"
                        :show-back-button="false"
                        save-label="Save step filters"
                        :filters-error="filtersForm.errors.filters"
                        @save="saveFilters"
                    />
                </div>
            </div>

            <!-- Mail metrics for this step -->
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
                        {{ formatNumber(props.stepMailMetrics.total) }} total
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3">
                    <div
                        v-for="card in stepMailMetricCards"
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

            <!-- Edit form -->
            <div
                class="rounded-[var(--bm-radius-md)] border p-6"
                style="background-color: var(--bm-surface); border-color: var(--bm-border); box-shadow: var(--bm-shadow-xs);"
            >
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-1.5">
                        <label
                            for="edit_delay_days"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Delay
                        </label>
                        <div class="flex gap-2">
                            <input
                                id="edit_delay_days"
                                v-model.number="form.delay_days"
                                type="number"
                                min="0"
                                :max="form.delay_type === 'hours' ? 8760 : 365"
                                class="w-24 shrink-0 rounded-[var(--bm-radius)] border px-3 py-2 text-sm tabular-nums focus-visible:outline-none"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1); font-family: var(--bm-font-mono);"
                            />
                            <select
                                v-model="form.delay_type"
                                aria-label="Delay unit"
                                class="flex-1 rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none"
                                style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            >
                                <option value="days">Days</option>
                                <option value="hours">Hours</option>
                            </select>
                        </div>
                        <InputError :message="form.errors.delay_days" />
                        <InputError :message="form.errors.delay_type" />
                    </div>

                    <div class="grid gap-1.5">
                        <label
                            for="edit_subject"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Subject
                        </label>
                        <input
                            id="edit_subject"
                            v-model="form.subject"
                            type="text"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            placeholder="Thanks for joining us!"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>

                    <div class="grid gap-1.5">
                        <label
                            for="edit_preview_text"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Preview text
                            <span class="font-normal" style="color: var(--bm-text-3);">
                                — preheader shown next to subject (optional)
                            </span>
                        </label>
                        <input
                            id="edit_preview_text"
                            v-model="form.preview_text"
                            type="text"
                            maxlength="255"
                            placeholder="A short teaser inbox clients show next to the subject"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                        />
                        <InputError :message="form.errors.preview_text" />
                    </div>

                    <div class="grid gap-1.5">
                        <label
                            for="edit_identity"
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1);"
                        >
                            Send from
                        </label>
                        <select
                            id="edit_identity"
                            v-model="form.email_identity_id"
                            class="w-full rounded-[var(--bm-radius)] border px-3 py-2 text-sm focus-visible:outline-none"
                            style="background-color: var(--bm-base); border-color: var(--bm-border); color: var(--bm-text-1);"
                            :disabled="form.processing || !hasEmailIdentities"
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
                        <InputError :message="form.errors.email_identity_id" />
                        <p
                            v-if="!hasEmailIdentities"
                            class="text-xs"
                            style="color: var(--bm-text-3);"
                        >
                            Add an email identity before editing steps.
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <label
                                class="text-sm font-medium"
                                style="color: var(--bm-text-1);"
                            >
                                Message content
                            </label>
                            <div class="flex items-center gap-3 text-xs font-medium">
                                <button
                                    type="button"
                                    class="transition hover:underline"
                                    style="color: var(--bm-accent);"
                                    @click="isTemplatePickerOpen = true"
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
                            :model-value="form.content"
                            :template="appliedTemplate"
                            placeholder="Write a friendly follow up..."
                            :disabled="form.processing"
                            @update:model-value="handleEditorUpdate"
                        />
                        <InputError :message="form.errors.content" />
                    </div>

                    <ComplianceWarnings
                        :without-unsubscribe-link="editedWithoutUnsubscribeLink"
                    />

                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-t pt-5"
                        style="border-color: var(--bm-border);"
                    >
                        <button
                            type="button"
                            class="text-sm font-medium transition hover:underline"
                            style="color: var(--bm-error);"
                            @click="confirmingDelete = true"
                        >
                            Delete step
                        </button>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="isSendingTestMail || form.processing"
                                @click="sendTestMail"
                            >
                                <PhPaperPlaneTilt :size="14" class="mr-1.5" />
                                {{ isSendingTestMail ? 'Sending…' : 'Send test email' }}
                            </Button>
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Saving…' : 'Save changes' }}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <ConfirmStatusToggleModal
            :is-open="statusToggle.confirming"
            :is-live="step.status === 'live'"
            :impact="props.statusImpact"
            label="step"
            @update:is-open="(open: boolean) => !open && (statusToggle.confirming = false)"
            @confirm="statusToggle.confirm"
        >
            <ComplianceWarnings
                v-if="step.status !== 'live'"
                :without-unsubscribe-link="savedWithoutUnsubscribeLink"
            />
        </ConfirmStatusToggleModal>

        <ConfirmDeleteStepModal
            :is-open="confirmingDelete"
            @update:is-open="(open: boolean) => !open && (confirmingDelete = false)"
            @confirm="confirmDelete"
        />

        <TemplatePickerModal
            :is-open="isTemplatePickerOpen"
            @update:is-open="(open: boolean) => (isTemplatePickerOpen = open)"
            @select="handleTemplateSelect"
        />

    </AppLayout>
</template>
