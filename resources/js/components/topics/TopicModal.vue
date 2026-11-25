<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PhStack } from '@phosphor-icons/vue';
import { useId, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface ListOption {
    id: number;
    name: string;
}

interface Props {
    lists: ListOption[];
    defaultListId?: number | null;
    lockList?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    defaultListId: null,
    lockList: false,
});

const isOpen = defineModel<boolean>('isOpen');

const emit = defineEmits<{
    created: [];
}>();

const listInputId = useId();
const nameInputId = useId();
const descriptionInputId = useId();

const form = useForm({
    name: '',
    description: '',
    list_id: props.defaultListId ?? props.lists[0]?.id ?? null,
});

watch(isOpen, (open) => {
    if (open) {
        form.reset();
        form.list_id = props.defaultListId ?? props.lists[0]?.id ?? null;
    }
});

function submit(): void {
    if (!form.list_id) {
        return;
    }

    form.transform(({ name, description }) => ({ name, description })).post(
        `/lists/${form.list_id}/topics`,
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('created');
                isOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div
                    class="mx-auto mb-3 w-auto rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-card p-0.5 shadow-sm"
                >
                    <div
                        class="relative overflow-hidden rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-muted p-2.5"
                    >
                        <PhStack
                            class="relative z-20 size-6 text-foreground"
                            weight="regular"
                        />
                    </div>
                </div>
                <DialogTitle class="text-center">Create topic</DialogTitle>
                <DialogDescription class="text-center">
                    Topics let subscribers opt out of content categories without
                    leaving the list.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="!lockList">
                    <label
                        :for="listInputId"
                        class="mb-1 block text-sm font-medium"
                    >
                        List
                    </label>
                    <div
                        class="inline-grid w-full grid-cols-[1fr_--spacing(8)]"
                    >
                        <select
                            :id="listInputId"
                            v-model.number="form.list_id"
                            name="list_id"
                            class="col-span-full row-start-1 w-full appearance-none rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-transparent py-2 pr-8 pl-3 text-sm max-sm:text-base/5"
                        >
                            <option
                                v-for="option in lists"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }}
                            </option>
                        </select>
                        <svg
                            viewBox="0 0 8 5"
                            width="8"
                            height="5"
                            fill="none"
                            aria-hidden="true"
                            class="pointer-events-none col-start-2 row-start-1 place-self-center"
                        >
                            <path d="M.5.5 4 4 7.5.5" stroke="currentcolor" />
                        </svg>
                    </div>
                    <InputError class="mt-1" :message="form.errors.list_id" />
                </div>
                <div>
                    <label
                        :for="nameInputId"
                        class="mb-1 block text-sm font-medium"
                    >
                        Name
                    </label>
                    <input
                        :id="nameInputId"
                        v-model="form.name"
                        name="name"
                        type="text"
                        class="w-full rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-transparent px-3 py-2 text-sm max-sm:text-base/5"
                    />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <label
                        :for="descriptionInputId"
                        class="mb-1 block text-sm font-medium"
                    >
                        Description
                    </label>
                    <textarea
                        :id="descriptionInputId"
                        v-model="form.description"
                        name="description"
                        rows="3"
                        class="w-full rounded-[var(--bm-radius-md)] border border-[var(--bm-border)] bg-transparent px-3 py-2 text-sm max-sm:text-base/5"
                    />
                    <InputError
                        class="mt-1"
                        :message="form.errors.description"
                    />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing || !form.list_id"
                >
                    Create topic
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
