<script setup lang="ts">
import DnsRecordTable from '@/components/onboarding/DnsRecordTable.vue';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority?: number | null;
    verified_at?: string | null;
}

defineProps<{
    records: DnsRecord[];
}>();
</script>

<template>
    <div
        class="rounded-[var(--bm-radius-md)] border bg-[var(--bm-surface)] p-4 shadow-[var(--bm-shadow-xs)]"
        style="border-color: var(--bm-border)"
    >
        <p class="mb-3 text-sm" style="color: var(--bm-text-2)">
            Add the following DNS records at your DNS provider. Once AWS detects
            them, click <strong>Recheck status</strong>.
        </p>
        <DnsRecordTable :records="records" show-status />
    </div>
</template>
