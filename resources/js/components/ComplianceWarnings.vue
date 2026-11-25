<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { edit as profileEdit } from '@/routes/profile';
import type { AppPageProps } from '@/types';

const props = withDefaults(
    defineProps<{
        /** The parts without `{{ unsubscribe_url }}`, for example "This content" or "Step 2". */
        withoutUnsubscribeLink: string[];
        /** The page where the user can add the link. */
        contentHref?: string | null;
    }>(),
    { contentHref: null },
);

const page = usePage<AppPageProps>();

const isMissingPostalAddress = computed(
    () => page.props.hasPostalAddress === false,
);
const isMissingUnsubscribeLink = computed(
    () => props.withoutUnsubscribeLink.length > 0,
);

const unsubscribeSubject = computed(() => {
    const labels = props.withoutUnsubscribeLink;

    if (labels.length <= 1) {
        return labels[0] ?? '';
    }

    return `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`;
});

const unsubscribeVerb = computed(() =>
    props.withoutUnsubscribeLink.length > 1 ? 'have' : 'has',
);
</script>

<template>
    <div
        v-if="isMissingUnsubscribeLink || isMissingPostalAddress"
        data-test="compliance-warnings"
        role="status"
        class="space-y-3 rounded-[var(--bm-radius-md)] border px-4 py-3"
        style="
            background-color: var(--bm-warning-light);
            border-color: rgba(168, 110, 0, 0.2);
        "
    >
        <div
            v-if="isMissingUnsubscribeLink"
            data-test="compliance-warning-unsubscribe"
            class="flex items-start gap-3"
        >
            <PhWarning
                :size="18"
                class="mt-0.5 shrink-0"
                style="color: var(--bm-warning)"
            />
            <div class="text-sm">
                <p class="font-semibold" style="color: var(--bm-warning)">
                    No unsubscribe link
                </p>
                <p class="mt-0.5" style="color: var(--bm-text-2)">
                    {{ unsubscribeSubject }} {{ unsubscribeVerb }} no
                    <code v-pre class="font-mono text-xs">{{
                        unsubscribe_url
                    }}</code>
                    link. Laws such as CAN-SPAM and GDPR ask for an unsubscribe
                    link in marketing email. BYOMailer still adds the one-click
                    unsubscribe header that Gmail and Yahoo require for bulk
                    senders.
                    <Link
                        v-if="contentHref"
                        :href="contentHref"
                        class="font-medium underline underline-offset-4 hover:opacity-80"
                        style="color: var(--bm-warning)"
                    >
                        Edit the content
                    </Link>
                </p>
            </div>
        </div>

        <div
            v-if="isMissingPostalAddress"
            data-test="compliance-warning-postal-address"
            class="flex items-start gap-3"
        >
            <PhWarning
                :size="18"
                class="mt-0.5 shrink-0"
                style="color: var(--bm-warning)"
            />
            <div class="text-sm">
                <p class="font-semibold" style="color: var(--bm-warning)">
                    No postal address
                </p>
                <p class="mt-0.5" style="color: var(--bm-text-2)">
                    Your account has no postal address. Laws such as CAN-SPAM
                    ask for a postal address in each marketing email. Add it in
                    <Link
                        :href="profileEdit().url"
                        class="font-medium underline underline-offset-4 hover:opacity-80"
                        style="color: var(--bm-warning)"
                    >
                        Settings, Profile</Link
                    >.
                </p>
            </div>
        </div>

        <p class="text-xs" style="color: var(--bm-text-3)">
            BYOMailer does not stop the send. Each sender is responsible for
            these rules.
        </p>
    </div>
</template>
