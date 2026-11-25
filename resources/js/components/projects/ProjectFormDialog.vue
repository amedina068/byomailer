<script setup lang="ts">
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    open: boolean;
    title: string;
    description: string;
    submitLabel: string;
    processingLabel: string;
    inputId: string;
    placeholder?: string;
    error?: string;
    processing: boolean;
}>();

const name = defineModel<string>('name', { required: true });

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'submit'): void;
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="grid gap-2">
                    <Label :for="inputId">Name</Label>
                    <Input
                        :id="inputId"
                        v-model="name"
                        type="text"
                        :placeholder="placeholder"
                        required
                    />
                    <InputError :message="error" />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? processingLabel : submitLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
