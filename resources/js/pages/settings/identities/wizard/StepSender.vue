<script setup lang="ts">
import { PhCheckCircle, PhWarning } from '@phosphor-icons/vue';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    selectedDomain: string;
    previewEmail: string;
    handleError: string;
    submitError: string | null;
    mailFromExisting: string | null;
    mailFromLoading: boolean;
    mailFromError: string;
}>();

const handle = defineModel<string>('handle', { required: true });
const displayName = defineModel<string>('displayName', { required: true });
const mailFromInput = defineModel<string>('mailFromInput', { required: true });
</script>

<template>
    <section class="space-y-6">
        <div class="space-y-1">
            <h1
                class="text-xl font-semibold tracking-tight"
                style="color: var(--bm-text-1)"
            >
                Sender address
            </h1>
            <p class="text-sm" style="color: var(--bm-text-2)">
                Pick the local part. This becomes the from address for
                newsletters and sequences that use this email identity.
            </p>
        </div>

        <div class="space-y-3">
            <div>
                <Label
                    for="handle"
                    class="text-xs font-semibold"
                    style="color: var(--bm-text-3)"
                >
                    Local part
                </Label>
                <div class="mt-2 flex items-center gap-2">
                    <Input
                        id="handle"
                        v-model="handle"
                        :aria-invalid="handleError ? true : undefined"
                        :aria-describedby="
                            handleError ? 'handle_error' : undefined
                        "
                        type="text"
                        autocomplete="off"
                        placeholder="hello"
                        class="rounded-[var(--bm-radius-md)] font-mono"
                    />
                    <span
                        class="font-mono text-sm"
                        style="color: var(--bm-text-3)"
                    >
                        @{{ selectedDomain }}
                    </span>
                </div>
                <p
                    v-if="handleError"
                    id="handle_error"
                    class="mt-1 text-xs"
                    style="color: var(--bm-error)"
                >
                    {{ handleError }}
                </p>
                <p
                    v-else-if="previewEmail"
                    class="mt-1 font-mono text-xs"
                    style="color: var(--bm-text-3)"
                >
                    From: {{ previewEmail }}
                </p>
            </div>

            <div>
                <Label
                    for="display_name"
                    class="text-xs font-semibold"
                    style="color: var(--bm-text-3)"
                >
                    Display name (optional)
                </Label>
                <Input
                    id="display_name"
                    v-model="displayName"
                    type="text"
                    autocomplete="off"
                    placeholder="My Company"
                    class="mt-2 rounded-[var(--bm-radius-md)]"
                />
            </div>

            <div
                class="rounded-[var(--bm-radius-md)] border px-4 py-3"
                style="
                    background-color: var(--bm-surface);
                    border-color: var(--bm-border);
                "
            >
                <Label
                    :for="
                        mailFromLoading || mailFromExisting
                            ? undefined
                            : 'mail_from_subdomain'
                    "
                    class="text-xs font-semibold"
                    style="color: var(--bm-text-3)"
                >
                    MAIL FROM domain
                </Label>

                <div
                    v-if="mailFromLoading"
                    class="mt-2 text-xs"
                    style="color: var(--bm-text-3)"
                >
                    Loading from AWS...
                </div>

                <div v-else-if="mailFromExisting" class="mt-2 space-y-1">
                    <div class="flex items-center gap-2">
                        <PhCheckCircle
                            :size="14"
                            style="color: var(--bm-success)"
                        />
                        <span
                            class="font-mono text-sm"
                            style="color: var(--bm-text-1)"
                        >
                            {{ mailFromExisting }}
                        </span>
                    </div>
                    <p class="text-xs" style="color: var(--bm-text-3)">
                        Using your existing MAIL FROM domain configured at AWS.
                        BYOMailer does not change it, to protect the reputation
                        of your domain.
                    </p>
                </div>

                <div v-else class="mt-2 space-y-1">
                    <Input
                        id="mail_from_subdomain"
                        v-model="mailFromInput"
                        :aria-invalid="mailFromError ? true : undefined"
                        :aria-describedby="
                            mailFromError ? 'mail_from_error' : undefined
                        "
                        type="text"
                        autocomplete="off"
                        :placeholder="`mas.${selectedDomain}`"
                        class="rounded-[var(--bm-radius-md)] font-mono"
                    />
                    <p
                        v-if="mailFromError"
                        id="mail_from_error"
                        class="text-xs"
                        style="color: var(--bm-error)"
                    >
                        {{ mailFromError }}
                    </p>
                    <p v-else class="text-xs" style="color: var(--bm-text-3)">
                        Subdomain used for SPF / return path. Must be a
                        subdomain of {{ selectedDomain }}.
                    </p>
                </div>
            </div>

            <Alert v-if="submitError" variant="destructive">
                <PhWarning :size="14" />
                <AlertTitle>Unable to save</AlertTitle>
                <AlertDescription>{{ submitError }}</AlertDescription>
            </Alert>
        </div>
    </section>
</template>
