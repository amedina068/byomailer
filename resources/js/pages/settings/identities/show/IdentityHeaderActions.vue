<script setup lang="ts">
import { PhArrowsClockwise, PhTrash } from '@phosphor-icons/vue';

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    title: string;
    description: string;
    isVerified: boolean;
    refreshing: boolean;
}>();

defineEmits<{
    (event: 'refresh'): void;
    (event: 'delete'): void;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <Heading
            variant="small"
            :title="title"
            :description="description"
            class="min-w-0 break-all"
        />

        <div class="flex shrink-0 items-center gap-2">
            <Button
                v-if="!isVerified"
                variant="outline"
                size="sm"
                :disabled="refreshing"
                class="rounded-[var(--bm-radius-md)]"
                @click="$emit('refresh')"
            >
                <PhArrowsClockwise
                    :size="14"
                    :class="{ 'animate-spin': refreshing }"
                    class="mr-2"
                />
                {{ refreshing ? 'Checking...' : 'Recheck status' }}
            </Button>
            <Button
                variant="outline"
                size="sm"
                class="rounded-[var(--bm-radius-md)]"
                style="color: var(--bm-error)"
                @click="$emit('delete')"
            >
                <PhTrash :size="14" class="mr-2" />
                Remove
            </Button>
        </div>
    </div>
</template>
