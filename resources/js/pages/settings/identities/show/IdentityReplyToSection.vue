<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, watch } from 'vue';

import EmailIdentityReplyToController from '@/actions/App/Http/Controllers/Settings/EmailIdentityReplyToController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    identityId: number;
    replyToEmail?: string | null;
    replyToName?: string | null;
}>();

const state = reactive({
    email: props.replyToEmail ?? '',
    name: props.replyToName ?? '',
    processing: false,
    error: '',
    emailError: '',
    nameError: '',
    success: false,
});

watch(
    () => [props.replyToEmail, props.replyToName] as const,
    ([email, name]) => {
        state.email = email ?? '';
        state.name = name ?? '';
    },
);

const save = async (): Promise<void> => {
    state.processing = true;
    state.error = '';
    state.emailError = '';
    state.nameError = '';
    state.success = false;

    try {
        await axios.put(
            EmailIdentityReplyToController.url({ emailIdentity: props.identityId }),
            {
                reply_to_email: state.email.trim(),
                reply_to_name: state.name.trim(),
            },
        );
        state.success = true;
        router.reload({ only: ['identity'] });
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.errors) {
            const errs = error.response.data.errors as Record<string, string[]>;
            state.emailError = errs.reply_to_email?.[0] ?? '';
            state.nameError = errs.reply_to_name?.[0] ?? '';

            if (!state.emailError && !state.nameError) {
                state.error = 'Unable to update Reply-To.';
            }
        } else if (axios.isAxiosError(error) && error.response?.data?.error) {
            state.error = error.response.data.error;
        } else {
            state.error = 'Unable to update Reply-To.';
        }
    } finally {
        state.processing = false;
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
                <p class="text-sm font-semibold" style="color: var(--bm-text-1)">
                    Reply-To
                </p>
                <p class="text-sm" style="color: var(--bm-text-2)">
                    Replies go to this address instead of the From address. Leave the
                    email empty to send replies to the From address.
                </p>
            </div>

            <Alert v-if="state.error" variant="destructive">
                <AlertDescription>{{ state.error }}</AlertDescription>
            </Alert>

            <Alert v-if="state.success">
                <AlertDescription>Reply-To saved.</AlertDescription>
            </Alert>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="reply_to_email" class="text-sm">Email</Label>
                    <Input
                        id="reply_to_email"
                        v-model="state.email"
                        type="email"
                        maxlength="255"
                        placeholder="support@example.com"
                        class="rounded-[var(--bm-radius-md)]"
                    />
                    <p
                        v-if="state.emailError"
                        class="text-xs"
                        style="color: var(--bm-error)"
                    >
                        {{ state.emailError }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="reply_to_name" class="text-sm">Name (optional)</Label>
                    <Input
                        id="reply_to_name"
                        v-model="state.name"
                        type="text"
                        maxlength="255"
                        placeholder="Support Team"
                        class="rounded-[var(--bm-radius-md)]"
                    />
                    <p
                        v-if="state.nameError"
                        class="text-xs"
                        style="color: var(--bm-error)"
                    >
                        {{ state.nameError }}
                    </p>
                </div>
            </div>

            <Button
                size="sm"
                :disabled="state.processing"
                class="rounded-[var(--bm-radius-md)]"
                @click="save"
            >
                {{ state.processing ? 'Saving...' : 'Save' }}
            </Button>
        </div>
    </div>
</template>
