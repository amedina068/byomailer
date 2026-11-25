<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

export interface Field {
    name: string;
    type: string;
    required?: boolean;
    description: string;
    children?: Field[];
}

defineProps<{
    title: string;
    fields: Field[];
}>();
</script>

<template>
    <div>
        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {{ title }}
        </h3>
        <div class="space-y-3">
            <div
                v-for="field in fields"
                :key="field.name"
                class="border-b border-dashed pb-3 last:border-b-0 last:pb-0"
            >
                <div class="flex items-center gap-2">
                    <code class="text-sm font-medium text-foreground">{{ field.name }}</code>
                    <span class="text-xs text-muted-foreground">{{ field.type }}</span>
                    <Badge
                        v-if="field.required"
                        variant="secondary"
                        class="px-1.5 py-0 text-[10px] font-medium uppercase"
                    >
                        required
                    </Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ field.description }}
                </p>

                <div v-if="field.children && field.children.length > 0" class="mt-2 ml-4 space-y-2">
                    <div
                        v-for="child in field.children"
                        :key="child.name"
                        class="border-l-2 border-dashed pl-3"
                    >
                        <div class="flex items-center gap-2">
                            <code class="text-xs font-medium text-foreground">{{ child.name }}</code>
                            <span class="text-xs text-muted-foreground">{{ child.type }}</span>
                            <Badge
                                v-if="child.required"
                                variant="secondary"
                                class="px-1.5 py-0 text-[10px] font-medium uppercase"
                            >
                                required
                            </Badge>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ child.description }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
