<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import InviteController from '@/actions/App/Http/Controllers/InviteController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    invite: {
        uuid: string;
        name: string;
        client_name: string;
        expires_at: string;
    };
    signature: {
        signature?: string | null;
        expires?: string | null;
    };
}>();

const signatureQuery = computed<Record<string, string>>(() => {
    const query: Record<string, string> = {};

    if (props.signature?.signature) {
        query.signature = props.signature.signature;
    }

    if (props.signature?.expires) {
        query.expires = props.signature.expires;
    }

    return query;
});

const subheading = `You're joining ${props.invite.client_name}. Enter the email address that received this invitation and set a password.`;
</script>

<template>
    <AuthLayout
        title="Accept invitation"
        :description="subheading"
    >
        <Head title="Accept invitation" />

        <div class="mx-auto w-full max-w-sm rounded-xl border border-border bg-card p-6 shadow-sm">
            <Form
                v-bind="InviteController.store.form(
                    { user: invite.uuid },
                    { query: signatureQuery }
                )"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-5"
            >
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        placeholder="you@example.com"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        We do not display your email. Enter it here to confirm
                        it matches the invitation we sent.
                    </p>
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Create a password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        placeholder="New password"
                        required
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autocomplete="new-password"
                        placeholder="Re-type password"
                        required
                    />
                </div>

                <Button type="submit" class="w-full" :disabled="processing">
                    Join workspace
                </Button>
            </Form>
        </div>
    </AuthLayout>
</template>
