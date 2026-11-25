<script setup lang="ts">
import VerificationStatusBadge from '@/components/identities/VerificationStatusBadge.vue';

defineProps<{
    identityStatus?: string | null;
    dkimStatus?: string | null;
    mailFromStatus?: string | null;
    mailFromSubdomain?: string | null;
}>();

const subStatusLabel = (value?: string | null): string => {
    if (!value) {
        return 'Pending';
    }
    return value
        .split(/[_\s]/)
        .map(
            (segment) =>
                segment.charAt(0).toUpperCase() + segment.slice(1).toLowerCase(),
        )
        .join(' ');
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <VerificationStatusBadge :status="identityStatus" />
            <span
                class="rounded-[var(--bm-radius-sm)] px-2 py-0.5 font-mono text-[11px] font-medium"
                style="background-color: var(--bm-base); color: var(--bm-text-3)"
            >
                DKIM: {{ subStatusLabel(dkimStatus) }}
            </span>
            <span
                class="rounded-[var(--bm-radius-sm)] px-2 py-0.5 font-mono text-[11px] font-medium"
                style="background-color: var(--bm-base); color: var(--bm-text-3)"
            >
                MAIL FROM: {{ subStatusLabel(mailFromStatus) }}
            </span>
        </div>

        <p
            v-if="mailFromSubdomain"
            class="font-mono text-xs"
            style="color: var(--bm-text-3)"
        >
            Return path: {{ mailFromSubdomain }}
        </p>
    </div>
</template>
