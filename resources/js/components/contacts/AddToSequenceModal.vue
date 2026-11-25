<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ListPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { index as sequencesIndex } from '@/routes/sequences';

interface SequenceOption {
    id: number;
    name: string;
    status: string;
}

const props = defineProps<{
    sequences: SequenceOption[];
    contactIds: number[];
}>();

const isOpen = defineModel<boolean>('isOpen');

const emit = defineEmits<{
    enrolled: [];
}>();

const step = ref<'select' | 'confirm'>('select');
const selectedSequenceId = ref<number | null>(null);
const processing = ref(false);

const contactCount = computed(() => props.contactIds.length);

const selectedSequence = computed(() =>
    props.sequences.find((sequence) => sequence.id === selectedSequenceId.value),
);

const reset = () => {
    step.value = 'select';
    selectedSequenceId.value = null;
    processing.value = false;
};

watch(isOpen, (open) => {
    if (!open) {
        reset();
    }
});

const goToConfirm = () => {
    if (selectedSequenceId.value === null) {
        return;
    }

    step.value = 'confirm';
};

const confirm = () => {
    if (selectedSequenceId.value === null || processing.value) {
        return;
    }

    processing.value = true;

    router.post(
        '/contacts/sequences',
        {
            sequence_id: selectedSequenceId.value,
            contact_ids: props.contactIds,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('enrolled');
                isOpen.value = false;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
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
                        <ListPlus class="relative z-20 size-6 text-foreground" />
                    </div>
                </div>
                <DialogTitle class="text-center">
                    {{
                        step === 'select'
                            ? 'Add to sequence'
                            : 'Confirm enrollment'
                    }}
                </DialogTitle>
                <DialogDescription class="text-center">
                    <template v-if="step === 'select'">
                        Choose a live sequence to enroll
                        {{ contactCount }}
                        {{ contactCount === 1 ? 'contact' : 'contacts' }} into.
                    </template>
                    <template v-else>
                        Add
                        {{ contactCount }}
                        {{ contactCount === 1 ? 'contact' : 'contacts' }} to
                        &ldquo;{{ selectedSequence?.name }}&rdquo;?
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="step === 'select'">
                <p
                    v-if="sequences.length === 0"
                    class="rounded-lg border border-border bg-muted/40 p-4 text-center text-sm text-muted-foreground"
                >
                    No live sequences yet.
                    <Link
                        :href="sequencesIndex().url"
                        class="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        Activate a sequence
                    </Link>
                    to enroll contacts.
                </p>

                <div
                    v-else
                    class="max-h-64 space-y-1 overflow-y-auto"
                    role="radiogroup"
                >
                    <button
                        v-for="sequence in sequences"
                        :key="sequence.id"
                        type="button"
                        role="radio"
                        :aria-checked="selectedSequenceId === sequence.id"
                        class="flex w-full items-center gap-3 rounded-lg border px-3 py-2.5 text-left text-sm transition"
                        :class="
                            selectedSequenceId === sequence.id
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:bg-muted/50'
                        "
                        @click="selectedSequenceId = sequence.id"
                    >
                        <span
                            class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                selectedSequenceId === sequence.id
                                    ? 'border-primary'
                                    : 'border-muted-foreground/40'
                            "
                        >
                            <span
                                v-if="selectedSequenceId === sequence.id"
                                class="size-2 rounded-full bg-primary"
                            />
                        </span>
                        <span class="font-medium text-foreground">
                            {{ sequence.name }}
                        </span>
                    </button>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <template v-if="step === 'select'">
                    <Button
                        type="button"
                        variant="outline"
                        @click="isOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="
                            selectedSequenceId === null ||
                            sequences.length === 0
                        "
                        @click="goToConfirm"
                    >
                        Add
                    </Button>
                </template>
                <template v-else>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="step = 'select'"
                    >
                        Back
                    </Button>
                    <Button
                        type="button"
                        :disabled="processing"
                        @click="confirm"
                    >
                        {{ processing ? 'Adding...' : 'Confirm' }}
                    </Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
