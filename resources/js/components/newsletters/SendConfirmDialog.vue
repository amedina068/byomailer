<script setup lang="ts">
import { computed } from 'vue';

import ComplianceWarnings from '@/components/ComplianceWarnings.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    action: 'send_now' | 'schedule';
    recipientCount: number | null;
    identityLabel: string | null;
    /** The value of the datetime-local input, in the time zone of the account. */
    scheduledFor: string;
    timezoneLabel: string;
    processing: boolean;
    withoutUnsubscribeLink: string[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ (e: 'confirm'): void }>();

const isSchedule = computed(() => props.action === 'schedule');
const confirmLabel = computed(() =>
    isSchedule.value ? 'Schedule send' : 'Send now',
);
const busyLabel = computed(() =>
    isSchedule.value ? 'Scheduling…' : 'Sending…',
);
const scheduledLabel = computed(
    () => `${props.scheduledFor.replace('T', ' ')} (${props.timezoneLabel})`,
);
</script>

<template>
    <Dialog :open="open" @update:open="open = $event">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        isSchedule
                            ? 'Schedule this newsletter?'
                            : 'Send newsletter now?'
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ isSchedule ? 'Send' : 'Send now' }}
                    to
                    <template v-if="recipientCount !== null">
                        <span
                            class="font-mono tabular-nums"
                            style="font-family: var(--bm-font-mono)"
                            >{{ recipientCount.toLocaleString() }}</span
                        >
                        {{ recipientCount === 1 ? 'contact' : 'contacts' }}
                    </template>
                    <template v-else>all eligible contacts</template>
                    <template v-if="identityLabel">
                        from {{ identityLabel }}</template
                    >.
                    <template v-if="isSchedule">
                        The send starts on {{ scheduledLabel }}.
                    </template>
                    <template v-else>
                        The send starts at once. A time in "Schedule for later"
                        is not used.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <ComplianceWarnings
                :without-unsubscribe-link="withoutUnsubscribeLink"
            />
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="outline">Keep editing</Button>
                </DialogClose>
                <Button :disabled="processing" @click="emit('confirm')">
                    {{ processing ? busyLabel : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
