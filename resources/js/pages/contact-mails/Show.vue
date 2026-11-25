<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as contactMailShow } from '@/routes/contact-mails';

const props = defineProps<{
    mail: {
        id: number;
        subject?: string | null;
        status: string;
        scheduled_at?: string | null;
        sent_at?: string | null;
        bounced_at?: string | null;
        unsubscribed_at?: string | null;
        from_email?: string | null;
        from_name?: string | null;
        failure_reason?: string | null;
        html_content?: string | null;
        text_content?: string | null;
        template_data?: Record<string, unknown> | null;
    };
}>();

const breadcrumbs = [
    {
        title: 'Contact mail',
        href: contactMailShow(props.mail.id).url,
    },
];

const formatDateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : null;

const deliveryLabel = computed(() => {
    if (props.mail.sent_at) {
        return `Sent ${formatDateTime(props.mail.sent_at)}`;
    }

    if (props.mail.scheduled_at) {
        return `Scheduled for ${formatDateTime(props.mail.scheduled_at)}`;
    }

    return 'Not scheduled';
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Mail · ${mail.subject ?? 'message'}`" />

        <section
            class="space-y-6 rounded-xl border border-sidebar-border/60 bg-card p-6 shadow-sm dark:border-sidebar-border"
        >
            <div>
                <h1 class="text-2xl font-semibold text-foreground">
                    {{ mail.subject ?? 'Newsletter message' }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Status: {{ mail.status }} · {{ deliveryLabel }}
                </p>
                <div
                    v-if="mail.failure_reason"
                    class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                >
                    {{ mail.failure_reason }}
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-sm text-muted-foreground">From</p>
                    <p class="font-medium">
                        {{ mail.from_name ?? mail.from_email ?? '—' }}
                        <span v-if="mail.from_email">
                            &lt;{{ mail.from_email }}&gt;
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Unsubscribed at</p>
                    <p class="font-medium">
                        {{ mail.unsubscribed_at ?? '—' }}
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-sm font-semibold text-muted-foreground">
                    HTML content
                </p>
                <div
                    v-if="mail.html_content"
                    class="mt-2 overflow-hidden rounded-md border border-border/40"
                >
                    <HtmlPreview :html="mail.html_content" />
                </div>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    No content
                </p>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-sm font-semibold text-muted-foreground">
                    Template data
                </p>
                <pre
                    class="mt-2 overflow-x-auto text-sm text-muted-foreground"
                    >{{
                        JSON.stringify(mail.template_data ?? {}, null, 2)
                    }}</pre
                >
            </div>
        </section>
    </AppLayout>
</template>
