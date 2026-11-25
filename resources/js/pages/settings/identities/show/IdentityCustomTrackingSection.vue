<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhArrowsClockwise, PhClock } from '@phosphor-icons/vue';
import axios from 'axios';
import { reactive, ref } from 'vue';

import CustomTrackingDomainCheckController from '@/actions/App/Http/Controllers/Settings/CustomTrackingDomainCheckController';
import CustomTrackingDomainController from '@/actions/App/Http/Controllers/Settings/CustomTrackingDomainController';
import VerificationStatusBadge from '@/components/identities/VerificationStatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import ConfirmDialog from '@/components/ui/confirm-dialog/ConfirmDialog.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    identityId: number;
    customTrackingDomain?: string | null;
    customTrackingDomainVerifiedAt?: string | null;
    customTrackingARecordIp?: string | null;
}>();

const state = reactive({
    enabled: !!props.customTrackingDomain,
    domain: props.customTrackingDomain ?? '',
    processing: false,
    error: '',
    fieldError: '',
    success: false,
    checking: false,
    checkError: '',
    checkNotVerified: false,
});

const confirmRemoveOpen = ref(false);

const confirmRemove = (): void => {
    confirmRemoveOpen.value = false;
    save();
};

const save = async (): Promise<void> => {
    state.processing = true;
    state.error = '';
    state.fieldError = '';
    state.success = false;

    try {
        await axios.put(
            CustomTrackingDomainController.update.url({
                emailIdentity: props.identityId,
            }),
            {
                enabled: state.enabled,
                custom_tracking_domain: state.enabled ? state.domain : null,
            },
        );
        state.success = true;
        if (!state.enabled) {
            state.domain = '';
        }
        router.reload({ only: ['identity'] });
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.errors) {
            const errs = error.response.data.errors as Record<string, string[]>;
            state.fieldError = errs.custom_tracking_domain?.[0] ?? '';
            state.error = errs.general?.[0] ?? '';
        } else if (axios.isAxiosError(error) && error.response?.data?.error) {
            state.error = error.response.data.error;
        } else {
            state.error = 'Unable to update the custom tracking domain.';
        }
    } finally {
        state.processing = false;
    }
};

const checkDns = async (): Promise<void> => {
    state.checking = true;
    state.checkError = '';
    state.checkNotVerified = false;
    state.success = false;

    try {
        const { data } = await axios.post<{ verified: boolean }>(
            CustomTrackingDomainCheckController.url({
                emailIdentity: props.identityId,
            }),
        );
        if (data.verified) {
            router.reload({ only: ['identity'] });
        } else {
            state.checkNotVerified = true;
        }
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.error) {
            state.checkError = error.response.data.error;
        } else {
            state.checkError = 'Unable to check DNS status.';
        }
    } finally {
        state.checking = false;
    }
};
</script>

<template>
    <div
        class="rounded-[var(--bm-radius-md)] border bg-[var(--bm-surface)] p-4 shadow-[var(--bm-shadow-xs)]"
        style="border-color: var(--bm-border)"
    >
        <div class="space-y-4">
            <p class="text-sm font-semibold" style="color: var(--bm-text-1)">
                Custom tracking domain
            </p>

            <Alert v-if="state.error" variant="destructive">
                <AlertDescription>{{ state.error }}</AlertDescription>
            </Alert>

            <Alert v-if="state.success">
                <AlertDescription
                    >Custom tracking domain saved.</AlertDescription
                >
            </Alert>

            <div class="flex items-center gap-2">
                <Checkbox id="custom_tracking" v-model="state.enabled" />
                <Label for="custom_tracking" class="cursor-pointer text-sm">
                    Use a custom tracking domain
                </Label>
            </div>

            <template v-if="state.enabled">
                <div class="grid gap-2">
                    <Label
                        for="custom_tracking_domain"
                        class="text-xs font-semibold"
                        style="color: var(--bm-text-3)"
                    >
                        Tracking domain
                    </Label>
                    <Input
                        id="custom_tracking_domain"
                        v-model="state.domain"
                        type="text"
                        placeholder="track.example.com"
                        class="rounded-[var(--bm-radius-md)] font-mono"
                    />
                    <p
                        v-if="state.fieldError"
                        class="text-xs"
                        style="color: var(--bm-error)"
                    >
                        {{ state.fieldError }}
                    </p>
                </div>

                <Button
                    size="sm"
                    :disabled="state.processing"
                    class="rounded-[var(--bm-radius-md)]"
                    @click="save"
                >
                    {{
                        state.processing ? 'Saving...' : 'Save tracking domain'
                    }}
                </Button>

                <div
                    v-if="customTrackingDomain"
                    class="space-y-3 rounded-[var(--bm-radius-md)] border bg-[var(--bm-surface-2)] p-4"
                    style="border-color: var(--bm-border)"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1)"
                        >
                            DNS configuration
                        </span>
                        <VerificationStatusBadge
                            :status="
                                customTrackingDomainVerifiedAt
                                    ? 'verified'
                                    : 'pending'
                            "
                        />
                    </div>

                    <p class="text-sm" style="color: var(--bm-text-2)">
                        Add the following A record at your DNS provider:
                    </p>

                    <div
                        class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3 sm:gap-4"
                    >
                        <div>
                            <div
                                class="font-medium"
                                style="color: var(--bm-text-3)"
                            >
                                Type
                            </div>
                            <div class="font-mono">A</div>
                        </div>
                        <div>
                            <div
                                class="font-medium"
                                style="color: var(--bm-text-3)"
                            >
                                Name
                            </div>
                            <div class="font-mono break-all">
                                {{ customTrackingDomain }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="font-medium"
                                style="color: var(--bm-text-3)"
                            >
                                Value
                            </div>
                            <div class="font-mono break-all">
                                {{ customTrackingARecordIp }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="!customTrackingDomainVerifiedAt"
                        class="space-y-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="state.checking"
                            class="rounded-[var(--bm-radius-md)]"
                            @click="checkDns"
                        >
                            <PhArrowsClockwise
                                :size="14"
                                :class="{ 'animate-spin': state.checking }"
                                class="mr-2"
                            />
                            Check status
                        </Button>

                        <Alert v-if="state.checkError" variant="destructive">
                            <AlertDescription>{{
                                state.checkError
                            }}</AlertDescription>
                        </Alert>

                        <Alert v-if="state.checkNotVerified">
                            <PhClock :size="14" />
                            <AlertDescription>
                                DNS record not yet detected. Allow time for
                                propagation.
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>
            </template>

            <Button
                v-if="!state.enabled && customTrackingDomain"
                type="button"
                variant="destructive"
                size="sm"
                :disabled="state.processing"
                class="rounded-[var(--bm-radius-md)]"
                @click="confirmRemoveOpen = true"
            >
                {{
                    state.processing ? 'Removing...' : 'Remove tracking domain'
                }}
            </Button>

            <ConfirmDialog
                v-model:is-open="confirmRemoveOpen"
                title="Remove tracking domain?"
                description="Open and click tracking will use the default AWS tracking domain again."
                confirm-label="Remove"
                :processing="state.processing"
                @confirm="confirmRemove"
            />
        </div>
    </div>
</template>
