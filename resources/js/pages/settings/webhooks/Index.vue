<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import WebhookController from '@/actions/App/Http/Controllers/Settings/WebhookController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WebhookEndpointRow from '@/components/webhooks/WebhookEndpointRow.vue';
import WebhookSecretBanner from '@/components/webhooks/WebhookSecretBanner.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index, show } from '@/routes/webhooks';
import { type BreadcrumbItem } from '@/types';

interface WebhookEndpointData {
    id: number;
    url: string;
    events: string[];
    active: boolean;
    disabled_reason: string | null;
    consecutive_failures: number;
    last_triggered_at: string | null;
}

interface DisabledReasons {
    Manual: string;
    AutoFailures: string;
}

interface WebhookEventData {
    value: string;
    group: string;
    description: string;
}

const props = defineProps<{
    endpoints: WebhookEndpointData[];
    webhookEvents: WebhookEventData[];
    disabledReasons: DisabledReasons;
    newSecret: string | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Webhooks', href: index().url }];

const groupLegends: Record<string, string> = {
    contact: 'Contact events',
    newsletter: 'Newsletter events',
    sequence: 'Sequence events',
};

const eventGroups = computed(() =>
    Object.entries(groupLegends)
        .map(([group, legend]) => ({
            legend,
            events: props.webhookEvents.filter((event) => event.group === group),
        }))
        .filter((group) => group.events.length > 0),
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Webhooks" />
        <h1 class="sr-only">Webhooks</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Webhooks"
                    description="Send realtime event callbacks to your own systems."
                />

                <WebhookSecretBanner :secret="newSecret" title="Endpoint created — copy your signing secret">
                    <template #description>
                        Store this secret in your receiver — it signs the
                        <code class="font-mono">X-Byomailer-Signature</code> header on every delivery. You won't see it again.
                    </template>
                </WebhookSecretBanner>

                <Form v-bind="WebhookController.store.form()" class="space-y-4" v-slot="{ errors, processing }">
                    <InputError :message="errors.endpoints" />

                    <div class="grid gap-2">
                        <Label for="url">Endpoint URL</Label>
                        <Input id="url" name="url" type="url" placeholder="https://example.com/webhooks/byomailer" required />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description (optional)</Label>
                        <Input id="description" name="description" placeholder="e.g. CRM sync" />
                        <InputError :message="errors.description" />
                    </div>

                    <fieldset
                        v-for="group in eventGroups"
                        :key="group.legend"
                        class="rounded border p-3"
                        style="border-color: var(--bm-border)"
                    >
                        <legend class="px-1 text-xs font-semibold uppercase tracking-wider" style="color: var(--bm-text-3)">
                            {{ group.legend }}
                        </legend>
                        <div class="flex flex-col gap-2">
                            <label v-for="event in group.events" :key="event.value" class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="events[]" :value="event.value" class="mt-1" />
                                <span>
                                    <code class="font-mono text-xs" style="color: var(--bm-text-1)">{{ event.value }}</code>
                                    <span class="block text-xs" style="color: var(--bm-text-4)">{{ event.description }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <InputError :message="errors.events" />

                    <Button :disabled="processing">{{ processing ? 'Creating…' : 'Create endpoint' }}</Button>
                </Form>
            </div>

            <div class="flex flex-col space-y-4">
                <Heading variant="small" title="Your endpoints" description="Manage and inspect your webhook endpoints." />

                <div
                    v-if="endpoints.length === 0"
                    class="rounded p-6 text-center text-sm"
                    style="border: 1px dashed var(--bm-border); color: var(--bm-text-4)"
                >
                    No webhook endpoints yet. Create one above to start receiving event callbacks.
                </div>

                <div v-else class="overflow-x-auto rounded" style="border: 1px solid var(--bm-border)">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--bm-border)">
                                <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Endpoint</th>
                                <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Status</th>
                                <th scope="col" class="px-4 py-2 text-left text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Last triggered</th>
                                <th scope="col" class="px-4 py-2 text-right text-[10px] uppercase tracking-wider" style="color: var(--bm-text-4)">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <WebhookEndpointRow
                                v-for="endpoint in endpoints"
                                :key="endpoint.id"
                                :endpoint="endpoint"
                                :view-url="show(endpoint.id).url"
                                :auto-failures-reason="disabledReasons.AutoFailures"
                            />
                        </tbody>
                    </table>
                </div>

                <p v-if="endpoints.length > 0" class="text-xs" style="color: var(--bm-text-4)">
                    Last-triggered times update after each delivery. <Link :href="index().url" class="underline">Refresh</Link> to reload.
                </p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
