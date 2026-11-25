<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhEnvelopeSimple } from '@phosphor-icons/vue';

import { Button } from '@/components/ui/button';
import { create, show } from '@/routes/identities';

defineProps<{
    pendingIdentityId: number | null;
}>();
</script>

<template>
    <div
        class="flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center"
        style="
            background-color: var(--bm-info-dim);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: var(--bm-radius-md);
        "
    >
        <div
            class="flex h-10 w-10 shrink-0 items-center justify-center"
            style="
                background-color: var(--bm-info-dim);
                border-radius: var(--bm-radius);
            "
        >
            <PhEnvelopeSimple :size="20" style="color: var(--bm-info)" />
        </div>
        <div v-if="pendingIdentityId" class="min-w-0 grow">
            <h3 class="font-semibold" style="color: var(--bm-text-1)">
                Finish the DNS setup
            </h3>
            <p class="text-sm" style="color: var(--bm-text-2)">
                Your email identity waits for its DNS records. Add the records
                at your DNS provider, then check the status.
            </p>
        </div>
        <div v-else class="min-w-0 grow">
            <h3 class="font-semibold" style="color: var(--bm-text-1)">
                Add a verified email identity
            </h3>
            <p class="text-sm" style="color: var(--bm-text-2)">
                Verify an email address or domain to prove that you own the
                email identity.
            </p>
        </div>
        <Button as-child size="sm" class="shrink-0">
            <Link
                v-if="pendingIdentityId"
                :href="show({ emailIdentity: pendingIdentityId }).url"
                >Finish DNS setup</Link
            >
            <Link v-else :href="create().url">Add email identity</Link>
        </Button>
    </div>
</template>
