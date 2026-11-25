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
    email: string;
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
                <DialogTitle>Remove from the suppression list?</DialogTitle>
                <DialogDescription>
                    {{ props.email }} bounced, so BYOMailer stopped mailing it.
                    Subscribe it again only if you know that the address works
                    now. Each new bounce lowers the reputation of your SES
                    account.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button @click="emit('confirm')">
                    Remove and subscribe
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
