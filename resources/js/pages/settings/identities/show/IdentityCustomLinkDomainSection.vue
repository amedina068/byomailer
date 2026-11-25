<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhArrowsClockwise, PhClock } from '@phosphor-icons/vue';
import axios from 'axios';
import { reactive, ref } from 'vue';

import CustomLinkDomainCheckController from '@/actions/App/Http/Controllers/Settings/CustomLinkDomainCheckController';
import CustomLinkDomainController from '@/actions/App/Http/Controllers/Settings/CustomLinkDomainController';
import VerificationStatusBadge from '@/components/identities/VerificationStatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import ConfirmDialog from '@/components/ui/confirm-dialog/ConfirmDialog.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    identityId: number;
    customLinkDomain?: string | null;
    customLinkDomainVerifiedAt?: string | null;
    customLinkCnameTarget?: string | null;
}>();

const state = reactive({
    enabled: !!props.customLinkDomain,
    domain: props.customLinkDomain ?? '',
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
            CustomLinkDomainController.update.url({
                emailIdentity: props.identityId,
            }),
            {
                enabled: state.enabled,
                custom_link_domain: state.enabled ? state.domain : null,
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
            state.fieldError = errs.custom_link_domain?.[0] ?? '';
            state.error = errs.general?.[0] ?? '';
        } else if (axios.isAxiosError(error) && error.response?.data?.error) {
            state.error = error.response.data.error;
        } else {
            state.error = 'Unable to update the custom link domain.';
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
            CustomLinkDomainCheckController.url({
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
            state.checkError = 'Unable to check link domain status.';
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
            <div class="space-y-1">
                <p
                    class="text-sm font-semibold"
                    style="color: var(--bm-text-1)"
                >
                    Custom link domain
                </p>
                <p class="text-sm" style="color: var(--bm-text-2)">
                    Serve unsubscribe and preference links on your own domain
                    instead of the main app domain. Keeps your sending
                    reputation isolated.
                </p>
            </div>

            <Alert v-if="state.error" variant="destructive">
                <AlertDescription>{{ state.error }}</AlertDescription>
            </Alert>

            <Alert v-if="state.success">
                <AlertDescription>Custom link domain saved.</AlertDescription>
            </Alert>

            <div class="flex items-center gap-2">
                <Checkbox id="custom_link_domain" v-model="state.enabled" />
                <Label for="custom_link_domain" class="cursor-pointer text-sm">
                    Use a custom link domain
                </Label>
            </div>

            <template v-if="state.enabled">
                <div class="grid gap-2">
                    <Label
                        for="custom_link_domain_input"
                        class="text-xs font-semibold"
                        style="color: var(--bm-text-3)"
                    >
                        Link domain
                    </Label>
                    <Input
                        id="custom_link_domain_input"
                        v-model="state.domain"
                        type="text"
                        placeholder="mail.example.com"
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
                    {{ state.processing ? 'Saving...' : 'Save link domain' }}
                </Button>

                <div
                    v-if="customLinkDomain"
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
                                customLinkDomainVerifiedAt
                                    ? 'verified'
                                    : 'pending'
                            "
                        />
                    </div>

                    <p class="text-sm" style="color: var(--bm-text-2)">
                        Add the following CNAME record at your DNS provider:
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
                            <div class="font-mono">CNAME</div>
                        </div>
                        <div>
                            <div
                                class="font-medium"
                                style="color: var(--bm-text-3)"
                            >
                                Name
                            </div>
                            <div class="font-mono break-all">
                                {{ customLinkDomain }}
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
                                {{ customLinkCnameTarget }}
                            </div>
                        </div>
                    </div>

                    <div v-if="!customLinkDomainVerifiedAt" class="space-y-2">
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
                                Not yet active. Cloudflare is still issuing the
                                TLS certificate — allow a few minutes after
                                adding the CNAME.
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>
            </template>

            <Button
                v-if="!state.enabled && customLinkDomain"
                type="button"
                variant="destructive"
                size="sm"
                :disabled="state.processing"
                class="rounded-[var(--bm-radius-md)]"
                @click="confirmRemoveOpen = true"
            >
                {{ state.processing ? 'Removing...' : 'Remove link domain' }}
            </Button>

            <ConfirmDialog
                v-model:is-open="confirmRemoveOpen"
                title="Remove link domain?"
                description="Unsubscribe and preference links will use the main app domain again."
                confirm-label="Remove"
                :processing="state.processing"
                @confirm="confirmRemove"
            />
        </div>
    </div>
</template>
