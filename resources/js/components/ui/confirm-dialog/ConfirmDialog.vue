<script setup lang="ts">
import { PhWarningCircle } from '@phosphor-icons/vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    title?: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'default' | 'destructive';
    processing?: boolean;
}

withDefaults(defineProps<Props>(), {
    title: 'Are you sure?',
    description: 'This action cannot be undone.',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    variant: 'destructive',
    processing: false,
});

const isOpen = defineModel<boolean>('isOpen');

const emit = defineEmits<{
    confirm: [];
}>();

function onConfirm(): void {
    emit('confirm');
}
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div
                    class="mx-auto mb-3 w-auto rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-card p-0.5"
                >
                    <div
                        class="rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-muted p-2.5"
                    >
                        <PhWarningCircle
                            class="size-6 text-amber-500"
                            weight="regular"
                        />
                    </div>
                </div>
                <DialogTitle class="text-center">{{ title }}</DialogTitle>
                <DialogDescription class="text-center">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:justify-center">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="isOpen = false"
                >
                    {{ cancelLabel }}
                </Button>
                <Button
                    type="button"
                    :variant="variant"
                    :disabled="processing"
                    @click="onConfirm"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
