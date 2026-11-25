<script setup lang="ts">
import { Deferred, Link } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';

import { index as contactsIndex } from '@/routes/contacts';

export type RateThresholds = Record<
    'bounce_review' | 'bounce_pause' | 'complaint_review' | 'complaint_pause',
    number
>;
export type RateLevel = 'ok' | 'review' | 'pause';
export type RateWindow = {
    key: string;
    sent: number;
    bounce_rate: number | null;
    complaint_rate: number | null;
    bounce_level: RateLevel | null;
    complaint_level: RateLevel | null;
};

const props = defineProps<{
    rates?: { warn: boolean; windows: RateWindow[] };
    thresholds: RateThresholds;
}>();

const labels: Record<string, string> = {
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
};
const mono = 'font-(family-name:--bm-font-mono)';
const link = 'font-medium text-(--bm-text-1) underline underline-offset-4';
const t = props.thresholds;

const levelClass: Record<RateLevel, string> = {
    ok: 'text-(--bm-text-1)',
    review: 'text-(--bm-warning)',
    pause: 'text-(--bm-error)',
};

// A text label next to the rate, so the level does not depend on color alone.
const levelLabel: Record<RateLevel, string | null> = {
    ok: null,
    review: 'Review level',
    pause: 'Pause level',
};

const notes = [
    `A bounce rate of ${t.bounce_review}% or a complaint rate of ${t.complaint_review}% starts an SES review. At ${t.bounce_pause}% bounce or ${t.complaint_pause}% complaint, SES can pause the account.`,
    'SES computes its own rate differently. For example, it does not count suppressed addresses. BYOMailer also counts soft bounces, so its bounce rate can be higher.',
    'These rates cover all sends from your team. The SES account card shows only your own AWS account.',
];
</script>

<template>
    <div
        class="overflow-hidden rounded-(--bm-radius-md) border border-(--bm-border) bg-(--bm-surface) shadow-(--bm-shadow-xs)"
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-(--bm-border) px-4 py-3.5"
        >
            <h2 class="text-sm font-semibold text-(--bm-text-1)">
                Bounce and complaint rates
            </h2>
            <span class="text-[11px] text-(--bm-text-3)"
                >Measured by BYOMailer</span
            >
        </div>
        <Deferred data="rates">
            <template #fallback>
                <div class="space-y-2 p-4">
                    <div
                        v-for="n in 3"
                        :key="n"
                        class="h-9 animate-pulse rounded-(--bm-radius) bg-(--bm-surface-2)"
                    />
                </div>
            </template>
            <div
                v-if="rates?.warn"
                class="flex items-start gap-2 bg-(--bm-warning-light) px-4 py-3 text-(--bm-warning)"
            >
                <PhWarning :size="16" class="mt-0.5 shrink-0" />
                <p class="text-pretty">
                    Your rate in the last 24 hours is at the level where SES
                    starts a review. Stop mailing contacts that bounced or
                    complained. Find them in
                    <Link
                        :href="contactsIndex().url"
                        class="font-medium underline underline-offset-4"
                        >Contacts</Link
                    >
                    and delete them or remove them from your lists.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead
                        class="border-b border-(--bm-border-strong) bg-(--bm-surface-2) text-[10px] tracking-wide text-(--bm-text-3) uppercase"
                    >
                        <tr>
                            <th
                                v-for="h in [
                                    'Window',
                                    'Sent',
                                    'Bounce rate',
                                    'Complaint rate',
                                ]"
                                :key="h"
                                scope="col"
                                class="px-4 py-2.5 text-right font-semibold first:text-left"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rates?.windows ?? []"
                            :key="row.key"
                            class="border-b border-(--bm-border)"
                        >
                            <td
                                class="px-4 py-3 font-medium whitespace-nowrap text-(--bm-text-1)"
                            >
                                {{ labels[row.key] }}
                            </td>
                            <td :class="mono" class="px-4 py-3 text-right">
                                {{ row.sent.toLocaleString() }}
                            </td>
                            <td
                                v-if="row.sent === 0"
                                colspan="2"
                                class="px-4 py-3 text-right text-(--bm-text-3)"
                            >
                                No sends yet
                            </td>
                            <template v-else>
                                <td
                                    :class="[
                                        mono,
                                        levelClass[row.bounce_level ?? 'ok'],
                                    ]"
                                    class="px-4 py-3 text-right"
                                >
                                    {{ row.bounce_rate }}%
                                    <span
                                        v-if="
                                            levelLabel[row.bounce_level ?? 'ok']
                                        "
                                        class="ml-1.5 font-sans text-[11px] font-medium"
                                        >{{
                                            levelLabel[row.bounce_level ?? 'ok']
                                        }}</span
                                    >
                                </td>
                                <td
                                    :class="[
                                        mono,
                                        levelClass[row.complaint_level ?? 'ok'],
                                    ]"
                                    class="px-4 py-3 text-right"
                                >
                                    {{ row.complaint_rate }}%
                                    <span
                                        v-if="
                                            levelLabel[
                                                row.complaint_level ?? 'ok'
                                            ]
                                        "
                                        class="ml-1.5 font-sans text-[11px] font-medium"
                                        >{{
                                            levelLabel[
                                                row.complaint_level ?? 'ok'
                                            ]
                                        }}</span
                                    >
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Deferred>
        <ul class="flex list-disc flex-col gap-1.5 py-4 pr-4 pl-8 text-pretty">
            <li v-for="note in notes" :key="note">{{ note }}</li>
            <li>
                Gmail shows its spam rate only in
                <a
                    href="https://postmaster.google.com"
                    target="_blank"
                    rel="noopener"
                    :class="link"
                    >Google Postmaster Tools</a
                >.
            </li>
        </ul>
    </div>
</template>
