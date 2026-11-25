<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { PhArrowLeft, PhArrowRight, PhX } from '@phosphor-icons/vue';
import { computed, onMounted, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/identities';

import {
    useAwsIdentities,
    type AwsIdentity,
} from './composables/useAwsIdentities';
import {
    useIdentityStore,
    type CreatedIdentity,
} from './composables/useIdentityStore';
import {
    FINAL_STEP,
    SENDER_STEP,
    STEPS,
    useIdentityWizardSteps,
} from './composables/useIdentityWizardSteps';
import StepDns from './wizard/StepDns.vue';
import StepSelectIdentity from './wizard/StepSelectIdentity.vue';
import StepSender from './wizard/StepSender.vue';
import StepTracking from './wizard/StepTracking.vue';

const props = defineProps<{
    existingDomains: string[];
    hasCredential: boolean;
}>();

const { step, next: nextStep, prev: prevStep, goTo } = useIdentityWizardSteps();

const {
    identities: awsIdentities,
    loading: awsLoading,
    loadError: awsLoadError,
    mailFromExisting,
    mailFromLoading,
    mailFromError,
    loadIdentities: loadAwsIdentities,
    fetchDetails: fetchAwsDetails,
    reset: resetAwsState,
} = useAwsIdentities(props.hasCredential);
const { submitting, submitError, store } = useIdentityStore();
const created = ref<CreatedIdentity | null>(null);

const source = ref<'existing' | 'new' | null>(null);
const selectedDomain = ref('');
const newDomainInput = ref('');
const newDomainError = ref('');
const handle = ref('');
const displayName = ref('');
const openTracking = ref(true);
const clickTracking = ref(true);
const mailFromInput = ref('');

const mailFromSubdomainPattern =
    /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/;
const handlePattern = /^[A-Za-z0-9._-]+$/;

const selectedAws = computed(() =>
    source.value === 'existing'
        ? awsIdentities.value.find((id) => id.name === selectedDomain.value)
        : undefined,
);

const isVerifiedAtAws = computed(
    () => selectedAws.value?.verification_status === 'SUCCESS',
);

const handleError = computed(() =>
    handle.value && !handlePattern.test(handle.value)
        ? 'Use letters, numbers, dots, dashes, or underscores only.'
        : '',
);

const mailFromInputError = computed(() => {
    if (mailFromExisting.value || mailFromLoading.value) {
        return '';
    }
    const value = mailFromInput.value.trim().toLowerCase();
    return mailFromSubdomainPattern.test(value)
        ? ''
        : `Enter a subdomain of ${selectedDomain.value}, for example mas.${selectedDomain.value}.`;
});

const previewEmail = computed(() => {
    if (!handle.value || !selectedDomain.value) {
        return '';
    }
    return `${handle.value}@${selectedDomain.value}`;
});

const canProceed = computed(() => {
    if (step.value === 1) {
        if (source.value === 'existing') {
            return !!selectedDomain.value;
        }
        if (source.value === 'new') {
            return newDomainInput.value.trim().length > 0;
        }
        return false;
    }
    if (step.value === SENDER_STEP) {
        if (!handle.value || !handlePattern.test(handle.value)) {
            return false;
        }
        if (mailFromLoading.value) {
            return false;
        }
        return mailFromInputError.value === '';
    }
    return true;
});

onMounted(() => {
    loadAwsIdentities();
});

const close = (): void => {
    router.visit(index().url);
};

const selectExisting = (identity: AwsIdentity): void => {
    if (identity.already_added) {
        return;
    }
    source.value = 'existing';
    selectedDomain.value = identity.name;
    fetchAwsDetails(identity.name, (suggested) => {
        mailFromInput.value = suggested;
    });
};

const chooseNew = (): void => {
    source.value = 'new';
    selectedDomain.value = '';
    newDomainInput.value = '';
    newDomainError.value = '';
    mailFromInput.value = '';
    resetAwsState();
};

const switchToExisting = (): void => {
    source.value = null;
    selectedDomain.value = '';
    newDomainInput.value = '';
    newDomainError.value = '';
    mailFromInput.value = '';
    resetAwsState();
};

const commitNewDomain = (): boolean => {
    newDomainError.value = '';
    const value = newDomainInput.value.trim().toLowerCase();
    if (!value || !/\./.test(value)) {
        newDomainError.value = 'Enter a valid domain, for example example.com.';
        return false;
    }
    if (props.existingDomains.includes(value)) {
        newDomainError.value = 'This domain is already added to BYOMailer.';
        return false;
    }
    selectedDomain.value = value;
    mailFromInput.value = `mas.${value}`;
    resetAwsState();
    return true;
};

const goBack = (): void => {
    if (step.value === 1) {
        close();
        return;
    }
    prevStep();
};

const submit = async (): Promise<void> => {
    const payload: Parameters<typeof store>[0] = {
        email: previewEmail.value,
        name: displayName.value || null,
        existing_aws_identity: source.value === 'existing',
        open_tracking_enabled: openTracking.value,
        click_tracking_enabled: clickTracking.value,
    };
    if (!mailFromExisting.value) {
        payload.mail_from_subdomain = mailFromInput.value.trim().toLowerCase();
    }
    const result = await store(payload);
    if (result) {
        created.value = result;
        goTo(FINAL_STEP);
    }
};

const goNext = async (): Promise<void> => {
    if (step.value === 1 && source.value === 'new') {
        if (!commitNewDomain()) {
            return;
        }
    }
    if (step.value === SENDER_STEP) {
        await submit();
        return;
    }
    if (step.value < FINAL_STEP) {
        nextStep();
    }
};

const finish = (): void => {
    if (created.value) {
        router.visit(show({ emailIdentity: created.value.id }).url);
        return;
    }
    close();
};
</script>

<template>
    <Head title="Add email identity" />

    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        style="background-color: var(--bm-base); color: var(--bm-text-1)"
    >
        <div class="flex min-h-screen flex-col">
            <header
                class="flex items-center justify-between border-b px-6 py-4"
                style="border-color: var(--bm-border)"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="font-mono text-[11px] font-medium"
                        style="color: var(--bm-text-3)"
                    >
                        STEP {{ String(step).padStart(2, '0') }} /
                        {{ String(STEPS.length).padStart(2, '0') }}
                    </span>
                    <span
                        class="text-sm font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        {{ STEPS[step - 1] }}
                    </span>
                </div>
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-[var(--bm-radius-md)] transition-colors hover:bg-[var(--bm-elevated)]"
                    aria-label="Close"
                    @click="close"
                >
                    <PhX :size="18" />
                </button>
            </header>

            <main class="flex-1 px-6 py-10">
                <div class="mx-auto max-w-2xl">
                    <StepSelectIdentity
                        v-if="step === 1"
                        v-model:new-domain-input="newDomainInput"
                        :existing-domains="existingDomains"
                        :aws-identities="awsIdentities"
                        :loading="awsLoading"
                        :load-error="awsLoadError"
                        :source="source"
                        :selected-domain="selectedDomain"
                        :new-domain-error="newDomainError"
                        :is-verified-at-aws="isVerifiedAtAws"
                        @choose-new="chooseNew"
                        @select-existing="selectExisting"
                        @switch-to-existing="switchToExisting"
                    />

                    <StepTracking
                        v-else-if="step === 2"
                        v-model:open-tracking="openTracking"
                        v-model:click-tracking="clickTracking"
                    />

                    <StepSender
                        v-else-if="step === SENDER_STEP"
                        v-model:handle="handle"
                        v-model:display-name="displayName"
                        v-model:mail-from-input="mailFromInput"
                        :selected-domain="selectedDomain"
                        :preview-email="previewEmail"
                        :handle-error="handleError"
                        :submit-error="submitError"
                        :mail-from-existing="mailFromExisting"
                        :mail-from-loading="mailFromLoading"
                        :mail-from-error="mailFromError || mailFromInputError"
                    />

                    <StepDns
                        v-else-if="step === FINAL_STEP"
                        :created="created"
                    />
                </div>
            </main>

            <footer
                class="border-t px-6 py-4"
                style="border-color: var(--bm-border)"
            >
                <div
                    class="mx-auto flex max-w-2xl items-center justify-between"
                >
                    <Button
                        v-if="step < FINAL_STEP"
                        type="button"
                        variant="ghost"
                        class="rounded-[var(--bm-radius-md)]"
                        @click="goBack"
                    >
                        <PhArrowLeft :size="14" class="mr-2" />
                        {{ step === 1 ? 'Cancel' : 'Back' }}
                    </Button>
                    <div v-else></div>

                    <Button
                        v-if="step < SENDER_STEP"
                        type="button"
                        :disabled="!canProceed"
                        class="rounded-[var(--bm-radius-md)]"
                        @click="goNext"
                    >
                        Next
                        <PhArrowRight :size="14" class="ml-2" />
                    </Button>
                    <Button
                        v-else-if="step === SENDER_STEP"
                        type="button"
                        :disabled="!canProceed || submitting"
                        class="rounded-[var(--bm-radius-md)]"
                        @click="goNext"
                    >
                        {{ submitting ? 'Saving...' : 'Save email identity' }}
                    </Button>
                    <Button
                        v-else
                        type="button"
                        class="rounded-[var(--bm-radius-md)]"
                        @click="finish"
                    >
                        Done
                    </Button>
                </div>
            </footer>
        </div>
    </div>
</template>
