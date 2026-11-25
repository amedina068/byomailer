<script setup lang="ts">
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
    isOpen: boolean;
    isLive: boolean;
    label?: string;
    impact?: { enrolled_contacts: number; pending_mails_due_now: number };
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        props.isLive
                            ? `Pause ${props.label ?? 'sequence'}?`
                            : `Activate ${props.label ?? 'sequence'}?`
                    }}
                </DialogTitle>
                <DialogDescription v-if="props.isLive">
                    This {{ props.label ?? 'sequence' }} sends no emails while
                    it is paused. Emails that come due wait, and they go out
                    when you activate it again.
                </DialogDescription>
                <DialogDescription v-else-if="!props.impact">
                    This {{ props.label ?? 'sequence' }} will go live and start
                    sending emails. Emails that came due during a pause go out
                    within minutes.
                </DialogDescription>
                <DialogDescription v-else>
                    This {{ props.label ?? 'sequence' }} will go live and start
                    sending emails.
                    <span class="font-(family-name:--bm-font-mono)">{{
                        props.impact.enrolled_contacts
                    }}</span>
                    {{
                        props.impact.enrolled_contacts === 1
                            ? 'contact is'
                            : 'contacts are'
                    }}
                    in this {{ props.label ?? 'sequence' }}.
                    <template v-if="props.impact.pending_mails_due_now > 0">
                        <span class="font-(family-name:--bm-font-mono)">{{
                            props.impact.pending_mails_due_now
                        }}</span>
                        {{
                            props.impact.pending_mails_due_now === 1
                                ? 'email that came due during the pause goes'
                                : 'emails that came due during the pause go'
                        }}
                        out within minutes.
                    </template>
                    <template v-else>No emails are due now.</template>
                </DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button @click="emit('confirm')">
                    {{ props.isLive ? 'Pause' : 'Activate' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
