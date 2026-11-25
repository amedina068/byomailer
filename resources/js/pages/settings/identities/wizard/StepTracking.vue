<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

const openTracking = defineModel<boolean>('openTracking', { required: true });
const clickTracking = defineModel<boolean>('clickTracking', { required: true });
</script>

<template>
    <section class="space-y-6">
        <div class="space-y-1">
            <h1
                class="text-xl font-semibold tracking-tight"
                style="color: var(--bm-text-1)"
            >
                Tracking
            </h1>
            <p class="text-sm" style="color: var(--bm-text-2)">
                Track opens and clicks on email sent from this email identity.
            </p>
        </div>

        <div
            class="space-y-4 rounded-[var(--bm-radius-md)] border px-4 py-4"
            style="
                background-color: var(--bm-surface);
                border-color: var(--bm-border);
            "
        >
            <div class="flex items-start gap-3">
                <Checkbox id="open_tracking" v-model="openTracking" />
                <Label for="open_tracking" class="cursor-pointer text-sm">
                    <span
                        class="block font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Track opens
                    </span>
                    <span class="block text-xs" style="color: var(--bm-text-3)">
                        Add a tracking pixel to every email.
                    </span>
                </Label>
            </div>
            <div class="flex items-start gap-3">
                <Checkbox id="click_tracking" v-model="clickTracking" />
                <Label for="click_tracking" class="cursor-pointer text-sm">
                    <span
                        class="block font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Track clicks
                    </span>
                    <span class="block text-xs" style="color: var(--bm-text-3)">
                        Rewrite outbound links through SES so we can measure
                        clicks.
                    </span>
                </Label>
            </div>
        </div>
    </section>
</template>
