<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { index as credentialsIndex } from '@/actions/App/Http/Controllers/Settings/EmailCredentialController';
import { useSesAccountStatus } from '@/composables/useSesAccountStatus';

export type SesCredential = Record<
    'max_24_hour_send' | 'max_send_rate' | 'sent_last_24_hours',
    number | null
> & {
    enforcement_status: string | null;
    sending_enabled: boolean | null;
    quota_fetched_at: string | null;
};

const props = defineProps<{ credential: SesCredential | null }>();

const mono = 'font-(family-name:--bm-font-mono)';
const link = 'font-medium text-(--bm-text-1) underline underline-offset-4';

const { isPaused, isUnderReview } = useSesAccountStatus(() => props.credential);

const status = computed(() => {
    const c = props.credential;
    if (!c?.quota_fetched_at) {
        return {
            label: 'Not checked',
            tone: 'text-(--bm-text-3) bg-(--bm-surface-2)',
            note: 'BYOMailer checks the SES status every hour. The first check has not run yet.',
            aws: false,
        };
    }
    if (isPaused.value) {
        return {
            label: 'Paused',
            tone: 'text-(--bm-error) bg-(--bm-error-light)',
            note: 'SES has paused sending on your AWS account. BYOMailer does not send until SES resumes it.',
            aws: true,
        };
    }
    if (isUnderReview.value) {
        return {
            label: 'Under review',
            tone: 'text-(--bm-warning) bg-(--bm-warning-light)',
            note: 'SES has your AWS account under review. Lower your bounce and complaint rates before the review ends.',
            aws: true,
        };
    }
    return {
        label: 'Healthy',
        tone: 'text-(--bm-success) bg-(--bm-success-light)',
        note: 'SES reports no problem with your AWS account.',
        aws: false,
    };
});

const facts = computed(() => {
    const c = props.credential;
    const n = (v: number | null | undefined) => v?.toLocaleString() ?? '—';
    return [
        ['Sent in 24h', n(c?.sent_last_24_hours)],
        ['24h quota', n(c?.max_24_hour_send)],
        ['Max per second', n(c?.max_send_rate)],
        [
            'Last checked',
            c?.quota_fetched_at
                ? new Date(c.quota_fetched_at).toLocaleString()
                : '—',
        ],
    ];
});
</script>

<template>
    <div
        class="overflow-hidden rounded-(--bm-radius-md) border border-(--bm-border) bg-(--bm-surface) shadow-(--bm-shadow-xs)"
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-(--bm-border) px-4 py-3.5"
        >
            <h2 class="text-sm font-semibold text-(--bm-text-1)">
                SES account
            </h2>
            <span
                v-if="credential"
                :class="[mono, status.tone]"
                class="inline-flex items-center gap-1.5 rounded-(--bm-radius-sm) px-2 py-0.5 text-[11px] font-medium"
            >
                <span class="size-1.5 rounded-full bg-current" />{{
                    status.label
                }}
            </span>
        </div>
        <p v-if="!credential" class="p-4">
            Connect your AWS credentials to see your SES account status.
            <Link :href="credentialsIndex().url" :class="link"
                >Go to email credentials</Link
            >
        </p>
        <div v-else class="flex flex-col gap-4 p-4">
            <p class="text-pretty">
                {{ status.note }}
                <a
                    v-if="status.aws"
                    href="https://console.aws.amazon.com/ses/home#/reputation"
                    target="_blank"
                    rel="noopener"
                    :class="link"
                    >Open SES reputation</a
                >
            </p>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div v-for="[term, value] in facts" :key="term" class="min-w-0">
                    <dt
                        class="truncate text-[10px] font-semibold tracking-wide text-(--bm-text-3) uppercase"
                    >
                        {{ term }}
                    </dt>
                    <dd :class="mono" class="mt-1 truncate text-(--bm-text-1)">
                        {{ value }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
