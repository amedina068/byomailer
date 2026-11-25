<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import ReputationRatesTable, {
    type RateThresholds,
    type RateWindow,
} from '@/components/reputation/ReputationRatesTable.vue';
import ReputationSesAccountCard, {
    type SesCredential,
} from '@/components/reputation/ReputationSesAccountCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as reputationShow } from '@/routes/reputation';

defineProps<{
    sesCredential: SesCredential | null;
    thresholds: RateThresholds;
    rates?: { warn: boolean; windows: RateWindow[] };
}>();
</script>

<template>
    <Head title="Reputation" />
    <AppLayout
        :breadcrumbs="[{ title: 'Reputation', href: reputationShow().url }]"
    >
        <section
            class="mx-auto flex w-full max-w-4xl flex-col gap-6 text-base text-(--bm-text-2) sm:text-sm"
        >
            <ReputationSesAccountCard :credential="sesCredential" />
            <ReputationRatesTable :rates="rates" :thresholds="thresholds" />
        </section>
    </AppLayout>
</template>
