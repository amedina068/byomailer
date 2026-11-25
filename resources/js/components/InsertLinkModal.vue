<script setup lang="ts">
import { ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    isOpen: boolean;
    initialUrl?: string;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    confirm: [url: string];
}>();

const linkUrl = ref('');

watch(
    () => props.isOpen,
    (open) => {
        if (open) {
            linkUrl.value = props.initialUrl ?? '';
        }
    },
);

const submit = (): void => {
    const url = linkUrl.value.trim();

    if (!url) {
        return;
    }

    emit('confirm', url);
    emit('update:isOpen', false);
};
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Insert link</DialogTitle>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid gap-1.5">
                    <Label for="link-url">URL</Label>
                    <input
                        id="link-url"
                        v-model="linkUrl"
                        type="url"
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        placeholder="https://example.com"
                    />
                </div>

                <p class="text-xs text-muted-foreground">
                    If the link has no UTM parameters, we'll automatically add
                    <span class="font-mono">utm_source</span> (step subject),
                    <span class="font-mono">utm_medium=mail</span>, and
                    <span class="font-mono">utm_campaign</span> (sequence name)
                    when the email is sent.
                </p>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button :disabled="!linkUrl.trim()" @click="submit">
                    Insert link
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
