<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { resubscribe } from '@/routes/contact-mails';

defineProps<{
    isTestMail?: boolean;
    alreadySubscribed?: boolean;
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
        <Head title="Subscribe again" />

        <div class="max-w-md text-center">
            <div
                v-if="isTestMail"
                class="mb-6 rounded-[var(--bm-radius-md)] border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-800 sm:text-sm"
            >
                <span class="font-semibold">Test email preview</span> — nothing
                will change. Your contacts are unaffected.
            </div>

            <template v-if="alreadySubscribed">
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    You are already subscribed
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, you will keep receiving
                    emails.
                </p>
            </template>

            <template v-else>
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    Subscribe again?
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, confirm below and we
                    will start emailing you again.
                </p>

                <div class="mt-6">
                    <Form
                        :action="resubscribe.url(contactMailUuid)"
                        method="post"
                        #default="{ processing }"
                    >
                        <Button type="submit" :disabled="processing"
                            >Yes, subscribe me again</Button
                        >
                    </Form>
                </div>
            </template>
        </div>
    </div>
</template>
