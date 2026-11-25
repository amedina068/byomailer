<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { PhArrowRight, PhWarning, PhX } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import { useSesAccountStatus } from '@/composables/useSesAccountStatus';
import { show as reputationShow } from '@/routes/reputation';
import type { AppPageProps } from '@/types';

const DISMISS_KEY = 'ses-review-banner-dismissed';

const page = usePage<AppPageProps>();
const { isPaused, isUnderReview } = useSesAccountStatus(
    () => page.props.sesAccountStatus,
);
const isReputationPage = computed(
    () => page.url.split('?')[0] === reputationShow().url,
);

const readDismissed = (): boolean => {
    try {
        return sessionStorage.getItem(DISMISS_KEY) === '1';
    } catch {
        return false;
    }
};
const dismissed = ref(readDismissed());

const dismiss = (): void => {
    dismissed.value = true;
    try {
        sessionStorage.setItem(DISMISS_KEY, '1');
    } catch {
        // Storage is blocked. The banner stays hidden until the next page load.
    }
};

const isVisible = computed(
    () =>
        !isReputationPage.value &&
        (isPaused.value || (isUnderReview.value && !dismissed.value)),
);
</script>

<template>
    <div
        v-if="isVisible"
        class="flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold"
        :class="
            isPaused
                ? 'bg-(--bm-error-light) text-(--bm-error)'
                : 'bg-(--bm-warning-light) text-(--bm-warning)'
        "
    >
        <PhWarning :size="14" class="shrink-0" />
        {{
            isPaused
                ? 'SES has paused sending on your AWS account.'
                : 'SES has your AWS account under review.'
        }}
        <Link
            :href="reputationShow().url"
            class="inline-flex items-center gap-1 underline underline-offset-4 hover:opacity-80"
        >
            View reputation
            <PhArrowRight :size="14" />
        </Link>
        <button
            v-if="!isPaused"
            type="button"
            aria-label="Hide this message"
            class="ml-2 inline-flex size-6 items-center justify-center rounded-(--bm-radius-sm) hover:opacity-80"
            @click="dismiss"
        >
            <PhX :size="14" />
        </button>
    </div>
</template>
