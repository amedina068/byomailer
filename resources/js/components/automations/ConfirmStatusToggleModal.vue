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
                    {{ props.isLive ? 'Pause automation?' : 'Activate automation?' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        props.isLive
                            ? 'This automation will stop running until you activate it again.'
                            : 'This automation will start running when its conditions are met.'
                    }}
                </DialogDescription>
            </DialogHeader>
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
