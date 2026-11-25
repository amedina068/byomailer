<script setup lang="ts">
import { List } from 'lucide-vue-next';

import ListForm from '@/components/lists/ListForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    redirectTo?: string;
    returnBack?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    redirectTo: undefined,
    returnBack: false,
});

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
                        <List class="relative z-20 size-6 text-foreground" />
                    </div>
                </div>
                <DialogTitle class="text-center">Create new list</DialogTitle>
                <DialogDescription class="text-center">
                    Name your list before you import contacts.
                </DialogDescription>
            </DialogHeader>

            <ListForm
                :redirect-to="props.redirectTo"
                :return-back="props.returnBack"
                @success="handleSuccess"
            />
        </DialogContent>
    </Dialog>
</template>
