<script setup lang="ts">
import { computed } from 'vue';

interface Step {
    number: number;
    title: string;
}

const props = defineProps<{
    steps: Step[];
    currentStep: number;
}>();

const currentStepTitle = computed(() => {
    const step = props.steps.find((s) => s.number === props.currentStep);
    return step?.title ?? '';
});
</script>

<template>
    <nav aria-label="Progress">
        <div class="text-center">
            <span class="text-sm font-medium text-muted-foreground">
                {{ currentStepTitle }}
            </span>
        </div>
    </nav>
</template>
