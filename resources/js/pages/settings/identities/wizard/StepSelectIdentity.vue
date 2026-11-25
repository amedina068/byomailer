<script setup lang="ts">
import {
    PhArrowLeft,
    PhCheckCircle,
    PhInfo,
    PhPlus,
    PhWarning,
} from '@phosphor-icons/vue';
import { computed } from 'vue';

import VerificationStatusBadge from '@/components/identities/VerificationStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface AwsIdentity {
    name: string;
    sending_enabled: boolean;
    verification_status: string;
    already_added: boolean;
}

const props = defineProps<{
    existingDomains: string[];
    awsIdentities: AwsIdentity[];
    loading: boolean;
    loadError: string | null;
    source: 'existing' | 'new' | null;
    selectedDomain: string;
    newDomainError: string;
    isVerifiedAtAws: boolean;
}>();

const newDomainInput = defineModel<string>('newDomainInput', {
    required: true,
});

const emit = defineEmits<{
    (e: 'choose-new'): void;
    (e: 'select-existing', identity: AwsIdentity): void;
    (e: 'switch-to-existing'): void;
}>();

const showDnsRequiredBanner = computed(() => {
    if (props.source === 'new') {
        return true;
    }
    return (
        props.source === 'existing' &&
        !!props.selectedDomain &&
        !props.isVerifiedAtAws
    );
});

const showVerifiedBanner = computed(
    () =>
        props.source === 'existing' &&
        !!props.selectedDomain &&
        props.isVerifiedAtAws,
);
</script>

<template>
    <section class="space-y-6">
        <div class="space-y-1">
            <h1
                class="text-xl font-semibold tracking-tight"
                style="color: var(--bm-text-1)"
            >
                Select a domain
            </h1>
            <p class="text-sm" style="color: var(--bm-text-2)">
                Pick a domain from your AWS account, or add a new domain.
            </p>
        </div>

        <Alert v-if="loadError" variant="destructive">
            <PhWarning :size="14" />
            <AlertTitle>AWS lookup failed</AlertTitle>
            <AlertDescription>{{ loadError }}</AlertDescription>
        </Alert>

        <!-- New-domain mode: only show input + toggle-back link -->
        <div v-if="source === 'new'" class="space-y-3">
            <div
                class="rounded-[var(--bm-radius-md)] border px-4 py-4"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                "
            >
                <Label
                    for="new_domain"
                    class="text-xs font-semibold"
                    style="color: var(--bm-text-3)"
                >
                    New domain
                </Label>
                <Input
                    id="new_domain"
                    v-model="newDomainInput"
                    type="text"
                    autocomplete="off"
                    placeholder="example.com"
                    class="mt-2 rounded-[var(--bm-radius-md)] font-mono"
                />
                <p
                    v-if="newDomainError"
                    class="mt-1 text-xs"
                    style="color: var(--bm-error)"
                >
                    {{ newDomainError }}
                </p>
                <p v-else class="mt-1 text-xs" style="color: var(--bm-text-3)">
                    BYOMailer adds it to AWS SES for you.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex items-center gap-1 text-xs font-medium transition-colors hover:text-[var(--bm-text-1)]"
                style="color: var(--bm-text-3)"
                @click="emit('switch-to-existing')"
            >
                <PhArrowLeft :size="12" />
                Select a domain from your AWS account
            </button>
        </div>

        <!-- Default mode: show existing list + "create new" CTA -->
        <div v-else class="space-y-4">
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-[var(--bm-radius-md)] border px-4 py-3 text-left transition-colors hover:bg-[var(--bm-surface-2)]"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                "
                @click="emit('choose-new')"
            >
                <span class="flex items-center gap-3">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-[var(--bm-radius-md)]"
                        style="
                            background-color: var(--bm-accent-bright-dim);
                            color: var(--bm-accent);
                        "
                    >
                        <PhPlus :size="16" weight="bold" />
                    </span>
                    <span>
                        <span
                            class="block text-sm font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Add a new domain
                        </span>
                        <span
                            class="block text-xs"
                            style="color: var(--bm-text-3)"
                        >
                            BYOMailer adds it to AWS SES for you.
                        </span>
                    </span>
                </span>
            </button>

            <div class="space-y-2">
                <p
                    class="font-mono text-[10px] font-semibold tracking-[1px] uppercase"
                    style="color: var(--bm-text-3)"
                >
                    Already in your AWS account
                </p>

                <div
                    v-if="loading"
                    class="rounded-[var(--bm-radius-md)] border px-4 py-6 text-center text-sm"
                    style="
                        background-color: var(--bm-surface);
                        border-color: var(--bm-border);
                        color: var(--bm-text-3);
                    "
                >
                    Loading domains from AWS...
                </div>

                <div
                    v-else-if="!loadError && props.awsIdentities.length === 0"
                    class="rounded-[var(--bm-radius-md)] border border-dashed px-4 py-6 text-center text-sm"
                    style="
                        border-color: var(--bm-border);
                        color: var(--bm-text-3);
                    "
                >
                    No domains found in your AWS account.
                </div>

                <button
                    v-for="identity in props.awsIdentities"
                    :key="identity.name"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 rounded-[var(--bm-radius-md)] border px-4 py-3 text-left transition-colors"
                    :class="{
                        'cursor-not-allowed opacity-60': identity.already_added,
                        'hover:bg-[var(--bm-surface-2)]':
                            !identity.already_added,
                        'ring-2 ring-[var(--bm-accent)]':
                            source === 'existing' &&
                            selectedDomain === identity.name,
                    }"
                    style="
                        background-color: var(--bm-surface);
                        border-color: var(--bm-border);
                    "
                    :disabled="identity.already_added"
                    :aria-pressed="
                        source === 'existing' &&
                        selectedDomain === identity.name
                    "
                    @click="emit('select-existing', identity)"
                >
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate font-mono text-sm"
                            style="color: var(--bm-text-1)"
                        >
                            {{ identity.name }}
                        </span>
                        <span
                            v-if="identity.already_added"
                            class="block text-xs"
                            style="color: var(--bm-text-3)"
                        >
                            Already in BYOMailer
                        </span>
                    </span>
                    <VerificationStatusBadge
                        :status="identity.verification_status"
                    />
                </button>
            </div>
        </div>

        <div
            v-if="showVerifiedBanner"
            class="flex items-start gap-3 rounded-[var(--bm-radius-md)] border px-4 py-3"
            style="
                background-color: var(--bm-success-light);
                border-color: rgba(26, 138, 74, 0.18);
                color: var(--bm-success);
            "
        >
            <PhCheckCircle :size="18" />
            <div class="text-sm">
                <p class="font-semibold">Already verified at AWS</p>
                <p class="mt-0.5" style="color: var(--bm-text-2)">
                    No DKIM records are necessary. BYOMailer still sets up the
                    MAIL FROM domain.
                </p>
            </div>
        </div>
        <div
            v-else-if="showDnsRequiredBanner"
            class="flex items-start gap-3 rounded-[var(--bm-radius-md)] border px-4 py-3"
            style="
                background-color: var(--bm-warning-light);
                border-color: rgba(168, 110, 0, 0.2);
                color: var(--bm-warning);
            "
        >
            <PhInfo :size="18" />
            <div class="text-sm">
                <p class="font-semibold">DNS verification required</p>
                <p class="mt-0.5" style="color: var(--bm-text-2)">
                    Add a few DNS records at your DNS provider before this email
                    identity can send. The last step shows the records.
                </p>
            </div>
        </div>
    </section>
</template>
