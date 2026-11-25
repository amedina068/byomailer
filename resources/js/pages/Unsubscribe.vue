<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { confirmUnsubscribe, resubscribe } from '@/routes/contact-mails';

defineProps<{
    isTestMail?: boolean;
    alreadyUnsubscribed?: boolean;
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
        <Head title="Unsubscribe" />

        <div class="max-w-md text-center">
            <div
                v-if="isTestMail"
                class="mb-6 rounded-[var(--bm-radius-md)] border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-800 sm:text-sm"
            >
                <span class="font-semibold">Test email preview</span> — nothing
                will change. Your contacts are unaffected.
            </div>

            <template v-if="alreadyUnsubscribed">
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    You are already unsubscribed
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, you will not receive
                    any more emails.
                </p>

                <!--
                    A contact often clicks the unsubscribe link in a second,
                    older email. Without this the page is a dead end and the
                    only way back is to contact support.
                -->
                <div class="mt-6 border-t border-border pt-6">
                    <p class="mb-4 text-base text-muted-foreground sm:text-sm">
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
            </template>

            <template v-else>
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    Unsubscribe from these emails?
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, confirm below and we
                    will stop emailing you.
                </p>

                <div class="mt-6">
                    <Form
                        :action="confirmUnsubscribe.url(contactMailUuid)"
                        method="post"
                        #default="{ processing }"
                    >
                        <Button type="submit" :disabled="processing"
                            >Yes, unsubscribe me</Button
                        >
                    </Form>
                </div>
            </template>
        </div>
    </div>
</template>
