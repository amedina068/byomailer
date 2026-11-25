<script setup lang="ts">
import { PhCheckCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

import DnsRecordTable from '@/components/onboarding/DnsRecordTable.vue';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority?: number | null;
    verified_at?: string | null;
}

interface CreatedIdentity {
    id: number;
    email: string;
    dns_records: DnsRecord[];
    identity_status?: string | null;
}

const props = defineProps<{
    created: CreatedIdentity | null;
}>();

const isVerified = computed(
    () => props.created?.identity_status === 'verified',
);

const pendingRecords = computed(() =>
    (props.created?.dns_records ?? []).filter((r) => !r.verified_at),
);
</script>

<template>
    <section class="space-y-6">
        <div class="space-y-1">
            <h1
                class="text-xl font-semibold tracking-tight"
                style="color: var(--bm-text-1)"
            >
                DNS records
            </h1>
            <p v-if="!isVerified" class="text-sm" style="color: var(--bm-text-2)">
                Add these records at your DNS provider. AWS detects them
                automatically within ~5 minutes after propagation.
            </p>
        </div>

        <div
            v-if="isVerified"
            class="flex items-start gap-3 rounded-[var(--bm-radius-md)] border px-4 py-3"
            style="
                background-color: var(--bm-success-light);
                border-color: rgba(26, 138, 74, 0.18);
                color: var(--bm-success);
            "
        >
            <PhCheckCircle :size="18" />
            <div class="text-sm">
                <p class="font-semibold">DNS records already verified</p>
                <p
                    v-if="created"
                    class="mt-0.5"
                    style="color: var(--bm-text-2)"
                >
                    {{ created.email }} is verified at AWS. No DNS work needed.
                </p>
            </div>
        </div>

        <div v-else-if="pendingRecords.length">
            <DnsRecordTable :records="pendingRecords" />
        </div>

        <div
            v-else
            class="rounded-[var(--bm-radius-md)] border border-dashed px-4 py-6 text-center text-sm"
            style="
                border-color: var(--bm-border);
                color: var(--bm-text-3);
            "
        >
            No DNS changes needed.
        </div>
    </section>
</template>
