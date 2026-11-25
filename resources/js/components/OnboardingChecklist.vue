<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, ClipboardList } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import Progress from '@/components/ui/progress/Progress.vue';
import type { AppPageProps } from '@/types';

const page = usePage<AppPageProps>();
const isOpen = ref(false);

const checklist = computed(() => page.props.onboardingChecklist);
const isCompleted = computed(
    () => checklist.value?.completed === checklist.value?.total,
);

function toggle(): void {
    isOpen.value = !isOpen.value;
}
</script>

<template>
    <div v-if="checklist && !isCompleted" class="fixed right-6 bottom-6 z-50">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 scale-95 opacity-0"
            enter-to-class="translate-y-0 scale-100 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 scale-100 opacity-100"
            leave-to-class="translate-y-2 scale-95 opacity-0"
        >
            <div
                v-if="isOpen"
                id="onboarding-checklist-panel"
                class="absolute right-0 bottom-14 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-border bg-card shadow-lg"
            >
                <div
                    class="flex items-center justify-between border-b border-border bg-muted/50 px-4 py-3"
                >
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">
                            Getting Started
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            <span class="font-mono">{{
                                checklist.completed
                            }}</span>
                            of
                            <span class="font-mono">{{ checklist.total }}</span>
                            completed
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Close setup guide"
                        class="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        @click="toggle"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </div>

                <div class="px-4 pt-3 pb-1">
                    <Progress
                        :value="checklist.completed"
                        :max="checklist.total"
                        class="h-1.5"
                    />
                </div>

                <div class="p-2">
                    <div
                        v-for="(step, index) in checklist.steps"
                        :key="step.key"
                        class="group relative"
                    >
                        <div
                            v-if="step.completed"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary"
                            >
                                <Check class="size-3 text-primary-foreground" />
                            </span>
                            <span class="text-muted-foreground line-through">
                                {{ step.label }}
                            </span>
                        </div>

                        <Link
                            v-else
                            :href="step.href"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors hover:bg-accent"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-primary text-xs font-medium text-primary"
                            >
                                {{ index + 1 }}
                            </span>
                            <span class="font-medium text-foreground">
                                {{ step.label }}
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
        </Transition>

        <Button
            size="default"
            :variant="isOpen ? 'default' : 'default'"
            class="gap-2 rounded-full shadow-lg"
            aria-controls="onboarding-checklist-panel"
            :aria-expanded="isOpen"
            @click="toggle"
        >
            <ClipboardList class="size-4" />
            <span>Setup Guide</span>
            <span
                class="flex size-5 items-center justify-center rounded-full bg-primary-foreground text-xs font-bold text-primary"
            >
                {{ checklist.completed }}/{{ checklist.total }}
            </span>
        </Button>
    </div>
</template>
