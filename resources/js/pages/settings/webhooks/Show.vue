<script setup lang="ts">
import { Deferred, Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import WebhookController from '@/actions/App/Http/Controllers/Settings/WebhookController';
import WebhookSecretController from '@/actions/App/Http/Controllers/Settings/WebhookSecretController';
import WebhookTestController from '@/actions/App/Http/Controllers/Settings/WebhookTestController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import WebhookDeliveryRow from '@/components/webhooks/WebhookDeliveryRow.vue';
import WebhookSecretBanner from '@/components/webhooks/WebhookSecretBanner.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index, show } from '@/routes/webhooks';
import { type BreadcrumbItem } from '@/types';

interface WebhookEndpointData {
    id: number;
    url: string;
    description: string | null;
    events: string[];
    active: boolean;
    disabled_reason: string | null;
    consecutive_failures: number;
    last_triggered_at: string | null;
    created_at: string | null;
}

interface DisabledReasons {
    Manual: string;
    AutoFailures: string;
}

interface WebhookDeliveryData {
    id: number;
    uuid: string;
    event_type: string;
    status: string;
    attempts: number;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    payload: Record<string, unknown>;
    created_at: string | null;
}

const props = defineProps<{
    endpoint: WebhookEndpointData;
    disabledReasons: DisabledReasons;
    newSecret: string | null;
    deliveries?: WebhookDeliveryData[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Webhooks', href: index().url },
    { title: 'Endpoint', href: show(props.endpoint.id).url },
];

const confirmRegenerate = ref(false);
const confirmDelete = ref(false);

function regenerate(): void {
    router.post(WebhookSecretController.url(props.endpoint.id), {}, { preserveScroll: true });
    confirmRegenerate.value = false;
}

function destroy(): void {
    router.delete(WebhookController.destroy.url(props.endpoint.id));
    confirmDelete.value = false;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Webhook endpoint" />
        <h1 class="sr-only">Webhook endpoint</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading variant="small" :title="endpoint.url" description="Webhook endpoint details and delivery log." />

                <WebhookSecretBanner :secret="newSecret" title="New signing secret">
                    <template #description>
                        Update your receiver with this secret — it signs the
                        <code class="font-mono">X-Byomailer-Signature</code> header. You won't see it again.
                    </template>
                </WebhookSecretBanner>

                <dl class="grid gap-2 text-sm">
                    <div class="flex gap-2">
                        <dt style="color: var(--bm-text-4)">Events:</dt>
                        <dd class="flex flex-wrap gap-1">
                            <code v-for="event in endpoint.events" :key="event" class="font-mono text-xs">{{ event }}</code>
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt style="color: var(--bm-text-4)">Status:</dt>
                        <dd>{{ endpoint.active ? 'Active' : endpoint.disabled_reason === disabledReasons.AutoFailures ? `Paused (${endpoint.consecutive_failures} failures)` : 'Inactive' }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap items-center gap-3">
                    <Form
                        v-bind="WebhookTestController.form(endpoint.id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing, recentlySuccessful }"
                    >
                        <Button type="submit" variant="outline" :disabled="processing" :aria-busy="processing" aria-label="Send a test delivery">
                            {{ processing ? 'Sending…' : 'Send test delivery' }}
                        </Button>
                        <span v-if="recentlySuccessful" class="ml-3 text-xs" style="color: var(--bm-success)">Test delivery queued — see the log below.</span>
                    </Form>

                    <Button variant="outline" @click="confirmRegenerate = true">Regenerate secret</Button>
                    <Button variant="ghost" class="text-destructive" @click="confirmDelete = true">Delete endpoint</Button>
                </div>
            </div>

            <div class="flex flex-col space-y-4">
                <Heading variant="small" title="Recent deliveries" description="The 50 most recent delivery attempts." />

                <Deferred :data="'deliveries'">
                    <template #fallback>
                        <div class="flex flex-col gap-2">
                            <div
                                v-for="n in 5"
                                :key="n"
                                class="h-10 animate-pulse rounded"
                                style="background-color: var(--bm-surface-2)"
                            />
                        </div>
                    </template>

                    <div
                        v-if="!deliveries || deliveries.length === 0"
                        class="rounded p-6 text-center text-sm"
                        style="border: 1px dashed var(--bm-border); color: var(--bm-text-4)"
                    >
                        No deliveries yet. Use “Send test delivery” to send a sample payload.
                    </div>

                    <div v-else class="overflow-x-auto rounded" style="border: 1px solid var(--bm-border)">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--bm-border)">
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Event</th>
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Status</th>
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Code</th>
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Attempts</th>
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">When</th>
                                    <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <WebhookDeliveryRow v-for="delivery in deliveries" :key="delivery.uuid" :delivery="delivery" />
                            </tbody>
                        </table>
                    </div>
                </Deferred>
            </div>
        </SettingsLayout>

        <ConfirmDialog
            v-model:is-open="confirmRegenerate"
            title="Regenerate signing secret?"
            description="All receivers using the current secret will fail signature verification until you update them with the new value."
            confirm-label="Regenerate"
            @confirm="regenerate"
        />

        <ConfirmDialog
            v-model:is-open="confirmDelete"
            title="Delete this endpoint?"
            description="This permanently removes the endpoint and stops all deliveries to it."
            confirm-label="Delete"
            @confirm="destroy"
        />
    </AppLayout>
</template>
