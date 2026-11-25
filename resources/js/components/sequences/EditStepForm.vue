<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SequenceStepTestMailController from '@/actions/App/Http/Controllers/SequenceStepTestMailController';
import InputError from '@/components/InputError.vue';
import TemplatePickerModal from '@/components/templates/TemplatePickerModal.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useTemplateApply, type EmailTemplateSummary } from '@/composables/useTemplateApply';
import { extractSlot, hydrateTemplate } from '@/lib/template-slot';
import { toast } from '@/composables/useToast';
import stepsRoutes from '@/routes/sequences/steps';

interface SequenceStep {
    id: number;
    sequence_id: number;
    delay_days: number;
    delay_type: 'days' | 'hours';
    status: 'draft' | 'live';
    message?: StepMessage | null;
}

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
    email_template_id?: number | null;
    template_slot_content?: string | null;
    email_template?: EmailTemplateSummary | null;
    email_identity?: EmailIdentity | null;
}

interface StepStats {
    contacts: number;
    unsubscribed: number;
    clicked: number;
}

interface StatLinks {
    contacts: string;
    unsubscribed: string;
    clicked: string;
}

const props = defineProps<{
    step: SequenceStep;
    stepIndex: number;
    sequenceId: number;
    stats: StepStats;
    viewMoreUrls?: StatLinks | null;
    emailIdentities: EmailIdentity[];
    defaultTemplate?: EmailTemplateSummary | null;
}>();

const emit = defineEmits<{
    'request-status-toggle': [stepId: number, isLive: boolean];
    'request-delete': [stepId: number];
}>();

const isTemplatePickerOpen = ref(false);

const form = useForm({
    delay_days: 0,
    delay_type: 'days' as 'days' | 'hours',
    subject: '',
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

watch(
    () => props.step,
    (step) => {
        form.delay_days = step.delay_days;
        form.delay_type = step.delay_type ?? 'days';
        form.subject = step.message?.subject ?? '';
        const messageHtml = step.message?.html_content ?? step.message?.text_content ?? '';
        const messageTemplateId = step.message?.email_template_id ?? null;
        // Editor binds to slot HTML directly. With a template applied, prefer
        // the persisted slot; otherwise fall back to extracting from the
        // composed html_content (legacy / no-template messages).
        form.content = messageTemplateId
            ? (step.message?.template_slot_content ?? extractSlot(messageHtml).slotContent ?? messageHtml)
            : (extractSlot(messageHtml).slotContent || messageHtml);
        form.email_identity_id = step.message?.email_identity_id
            ? String(step.message.email_identity_id)
            : '';
        form.email_template_id = messageTemplateId;
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

const submit = () => {
    form.transform((data) => ({
        ...data,
        content: appliedTemplate.value
            ? hydrateTemplate(appliedTemplate.value.html_content, data.content)
            : data.content,
    })).patch(stepsRoutes.update([props.sequenceId, props.step.id]).url, {
        preserveScroll: true,
    });
};

const stepStatCards = computed(() => {
    const links = props.viewMoreUrls ?? null;

    return [
        {
            key: 'contacts',
            label: 'Contacts in this step',
            description: 'Queued or sent',
            value: props.stats.contacts,
            href: links?.contacts ?? null,
        },
        {
            key: 'unsubscribed',
            label: 'Unsubscribed here',
            description: 'Opted out',
            value: props.stats.unsubscribed,
            href: links?.unsubscribed ?? null,
        },
        {
            key: 'clicked',
            label: 'Clicked a link',
            description: 'Engaged contacts',
            value: props.stats.clicked,
            href: links?.clicked ?? null,
        },
    ];
});

const formatNumber = (value: number): string => value.toLocaleString();

const hasEmailIdentities = computed(
    () => props.emailIdentities.length > 0,
);

const isSendingTestMail = ref(false);

const sendTestMail = () => {
    isSendingTestMail.value = true;

    router.post(
        SequenceStepTestMailController([props.sequenceId, props.step.id]).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Test email sent to your inbox.');
            },
            onError: () => {
                toast.error('Failed to send test email.');
            },
            onFinish: () => {
                isSendingTestMail.value = false;
            },
        },
    );
};
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-muted-foreground">
                    Step {{ stepIndex + 1 }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Switch
                    :model-value="step.status === 'live'"
                    @update:model-value="
                        emit(
                            'request-status-toggle',
                            step.id,
                            step.status === 'live',
                        )
                    "
                />
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div
                v-for="card in stepStatCards"
                :key="card.key"
                class="rounded-lg border border-border/70 bg-muted/40 p-3"
            >
                <p class="text-xs font-medium uppercase text-muted-foreground">
                    {{ card.label }}
                </p>
                <p class="mt-2 text-xl font-semibold text-foreground">
                    {{ formatNumber(card.value) }}
                </p>
                <div
                    class="mt-2 flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ card.description }}</span>
                    <Link
                        v-if="card.href"
                        :href="card.href"
                        class="font-medium text-primary hover:underline"
                    >
                        View more
                    </Link>
                </div>
            </div>
        </div>

        <form class="mt-4 space-y-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <label for="edit_delay_days" class="text-sm font-medium">
                    Delay
                </label>
                <div class="flex gap-2">
                    <input
                        id="edit_delay_days"
                        v-model.number="form.delay_days"
                        type="number"
                        min="0"
                        :max="form.delay_type === 'hours' ? 8760 : 365"
                        class="w-24 shrink-0 rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <select
                        v-model="form.delay_type"
                        class="flex-1 rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="days">Days</option>
                        <option value="hours">Hours</option>
                    </select>
                </div>
                <InputError :message="form.errors.delay_days" />
                <InputError :message="form.errors.delay_type" />
            </div>

            <div class="grid gap-2">
                <label for="edit_subject" class="text-sm font-medium">
                    Subject
                </label>
                <input
                    id="edit_subject"
                    v-model="form.subject"
                    type="text"
                    class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    placeholder="Thanks for joining us!"
                />
                <InputError :message="form.errors.subject" />
            </div>

            <div class="grid gap-2">
                <label for="edit_identity" class="text-sm font-medium">
                    Send from
                </label>
                <select
                    id="edit_identity"
                    v-model="form.email_identity_id"
                    class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :disabled="form.processing || !hasEmailIdentities"
                    required
                >
                    <option value="" disabled>Select sender</option>
                    <option
                        v-for="identity in props.emailIdentities"
                        :key="identity.id"
                        :value="String(identity.id)"
                    >
                        {{ identity.name ?? identity.email }} &lt;{{
                            identity.email
                        }}&gt;
                    </option>
                </select>
                <InputError :message="form.errors.email_identity_id" />
                <p v-if="!hasEmailIdentities" class="text-xs text-muted-foreground">
                    Add an email identity before editing steps.
                </p>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <label for="edit_content" class="text-sm font-medium">
                        Message content
                    </label>
                    <button
                        type="button"
                        class="text-xs font-medium text-primary transition hover:underline"
                        @click="isTemplatePickerOpen = true"
                    >
                        {{ appliedTemplate ? 'Change template' : 'Use template' }}
                    </button>
                </div>
                <div
                    v-if="appliedTemplate"
                    class="flex items-center justify-between rounded-[var(--bm-radius-md)] border border-primary/40 bg-primary/5 px-3 py-2 text-xs"
                >
                    <span class="font-mono text-foreground">
                        Template: <strong>{{ appliedTemplate.name }}</strong>
                    </span>
                    <button
                        type="button"
                        class="font-medium text-destructive transition hover:underline"
                        @click="removeTemplate"
                    >
                        Remove template
                    </button>
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

            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="text-sm text-destructive hover:underline"
                    @click="emit('request-delete', step.id)"
                >
                    Delete step
                </button>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="isSendingTestMail || form.processing"
                        @click="sendTestMail"
                    >
                        {{ isSendingTestMail ? 'Sending...' : 'Send test email' }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : 'Save changes' }}
                    </Button>
                </div>
            </div>
        </form>

        <TemplatePickerModal
            :is-open="isTemplatePickerOpen"
            @update:is-open="(open: boolean) => (isTemplatePickerOpen = open)"
            @select="handleTemplateSelect"
        />
    </div>
</template>
