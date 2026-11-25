<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { resubscribe } from '@/routes/contact-mails';

defineProps<{
    isTestMail?: boolean;
    contact: {
        name: string | null;
        email: string;
    };
    contactMailUuid: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10"
    >
        <Head title="Unsubscribed" />

        <div class="max-w-md text-center">
            <div
                v-if="isTestMail"
                class="mb-6 rounded-[var(--bm-radius-md)] border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <span class="font-semibold">Test email preview</span> — no
                action was taken. Your contacts are unchanged.
            </div>

            <h1 class="text-2xl font-semibold text-foreground">
                You have been unsubscribed
            </h1>
            <p class="mt-2 text-muted-foreground">
                {{ contact.name ?? contact.email }}, you have successfully
                unsubscribed.
            </p>

            <div class="mt-6 border-t border-border pt-6">
                <p class="mb-4 text-sm text-muted-foreground">
                    Unsubscribed by mistake? You can subscribe again.
                </p>
                <Form
                    :action="resubscribe.url(contactMailUuid)"
                    method="post"
                    #default="{ processing }"
                >
                    <Button type="submit" :disabled="processing"
                        >Subscribe again</Button
                    >
                </Form>
            </div>
        </div>
    </div>
</template>
