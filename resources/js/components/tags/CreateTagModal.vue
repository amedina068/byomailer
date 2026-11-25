<script setup lang="ts">
import { Tag } from 'lucide-vue-next';

import TagForm from '@/components/tags/TagForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const isOpen = defineModel<boolean>('isOpen');

const emit = defineEmits<{
    created: [];
}>();

const handleSuccess = () => {
    emit('created');
    isOpen.value = false;
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div
                    class="mx-auto mb-3 w-auto rounded-full border border-border bg-card p-0.5 shadow-sm"
                >
                    <div
                        class="relative overflow-hidden rounded-full border border-border bg-muted p-2.5"
                    >
                        <Tag class="relative z-20 size-6 text-foreground" />
                    </div>
                </div>
                <DialogTitle class="text-center">Create new tag</DialogTitle>
                <DialogDescription class="text-center">
                    Tags are lightweight labels for organizing contacts.
                </DialogDescription>
            </DialogHeader>

            <TagForm @success="handleSuccess" />
        </DialogContent>
    </Dialog>
</template>
