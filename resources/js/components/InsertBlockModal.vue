<script setup lang="ts">
import axios from 'axios';
import { Blocks } from 'lucide-vue-next';
import { ref, watch } from 'vue';

import BlockListController from '@/actions/App/Http/Controllers/BlockListController';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Block {
    id: number;
    name: string;
}

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    select: [block: Block];
}>();

const blocks = ref<Block[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);

const loadBlocks = async (): Promise<void> => {
    loading.value = true;
    loadError.value = null;

    try {
        const { data } = await axios.get<{ blocks: Block[] }>(
            BlockListController.url(),
        );

        blocks.value = data.blocks;
    } catch (error) {
        loadError.value = axios.isAxiosError(error)
            ? (error.response?.data?.message ?? 'Failed to load blocks')
            : 'Failed to load blocks';
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.isOpen,
    (open) => {
        if (open) {
            loadBlocks();
        }
    },
);

const selectBlock = (block: Block): void => {
    emit('select', block);
    emit('update:isOpen', false);
};
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Insert block</DialogTitle>
            </DialogHeader>

            <p class="text-sm text-muted-foreground">
                Select a block to insert its content into the editor.
            </p>

            <div
                v-if="loadError"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
            >
                {{ loadError }}
                <button
                    type="button"
                    class="ml-2 underline"
                    @click="loadBlocks"
                >
                    Retry
                </button>
            </div>

            <div v-else-if="loading" class="flex flex-col gap-2">
                <div
                    v-for="i in 3"
                    :key="i"
                    class="h-14 animate-pulse rounded-lg bg-muted"
                />
            </div>

            <div
                v-else-if="blocks.length > 0"
                class="flex max-h-80 flex-col gap-1 overflow-y-auto"
            >
                <button
                    v-for="block in blocks"
                    :key="block.id"
                    type="button"
                    class="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5 text-left transition hover:bg-muted"
                    @click="selectBlock(block)"
                >
                    <Blocks class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span class="text-sm font-medium">{{ block.name }}</span>
                </button>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border py-12 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-muted"
                >
                    <Blocks class="h-6 w-6 text-muted-foreground" />
                </div>
                <div>
                    <p class="text-sm font-medium">No blocks yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Create blocks in the Blocks section to reuse content
                        across emails.
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
