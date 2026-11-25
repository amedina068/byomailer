<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { optIn } from '@/routes/contact-mails';

defineProps<{
    alreadyConfirmed: boolean;
    contact: {
        name: string | null;
        email: string;
    };
    list: {
        name: string;
    };
    contactMailUuid: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10"
    >
        <Head title="Confirm your subscription" />

        <div class="max-w-md text-center">
            <template v-if="alreadyConfirmed">
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    Your subscription is already confirmed
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, you are subscribed to
                    {{ list.name }}.
                </p>
            </template>

            <template v-else>
                <h1
                    class="text-2xl font-semibold tracking-tight text-balance text-foreground"
                >
                    Confirm your subscription
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ contact.name ?? contact.email }}, confirm below to
                    receive emails from {{ list.name }}.
                </p>

                <div class="mt-6">
                    <Form
                        :action="optIn.url(contactMailUuid)"
                        method="post"
                        #default="{ processing }"
                    >
                        <Button type="submit" :disabled="processing"
                            >Yes, subscribe me</Button
                        >
                    </Form>
                </div>
            </template>
        </div>
    </div>
</template>
