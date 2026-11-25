<script setup lang="ts">
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import EmailIdentityTrackingController from '@/actions/App/Http/Controllers/Settings/EmailIdentityTrackingController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    identityId: number;
    initialOpenTracking: boolean;
    initialClickTracking: boolean;
}>();

const tracking = reactive({
    openTracking: props.initialOpenTracking,
    clickTracking: props.initialClickTracking,
    processing: false,
    error: '',
    success: false,
});

const save = async (): Promise<void> => {
    tracking.processing = true;
    tracking.error = '';
    tracking.success = false;

    try {
        await axios.put(
            EmailIdentityTrackingController.url({ emailIdentity: props.identityId }),
            {
                open_tracking_enabled: tracking.openTracking,
                click_tracking_enabled: tracking.clickTracking,
            },
        );
        tracking.success = true;
        router.reload({ only: ['identity'] });
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.error) {
            tracking.error = error.response.data.error;
        } else {
            tracking.error = 'Unable to update tracking settings.';
        }
    } finally {
        tracking.processing = false;
    }
};
</script>

<template>
    <div
        class="rounded-[var(--bm-radius-md)] border bg-[var(--bm-surface)] p-4 shadow-[var(--bm-shadow-xs)]"
        style="border-color: var(--bm-border)"
    >
        <div class="space-y-4">
            <p class="text-sm font-semibold" style="color: var(--bm-text-1)">
                Tracking
            </p>

            <Alert v-if="tracking.error" variant="destructive">
                <AlertDescription>{{ tracking.error }}</AlertDescription>
            </Alert>

            <Alert v-if="tracking.success">
                <AlertDescription>Tracking saved.</AlertDescription>
            </Alert>

            <div class="flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-2">
                    <Checkbox id="open_tracking" v-model="tracking.openTracking" />
                    <Label for="open_tracking" class="cursor-pointer text-sm">
                        Track opens
                    </Label>
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="click_tracking" v-model="tracking.clickTracking" />
                    <Label for="click_tracking" class="cursor-pointer text-sm">
                        Track clicks
                    </Label>
                </div>
                <Button
                    size="sm"
                    :disabled="tracking.processing"
                    class="rounded-[var(--bm-radius-md)]"
                    @click="save"
                >
                    {{ tracking.processing ? 'Saving...' : 'Save' }}
                </Button>
            </div>
        </div>
    </div>
</template>
