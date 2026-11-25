<script setup lang="ts">
import { computed } from 'vue';

import { LINK_NAME_MAX_LENGTH, normalizeLinkName } from '@/lib/linkClicks';

defineOptions({ inheritAttrs: false });

defineProps<{
    id: string;
    linkNames: string[];
    hint?: string;
}>();

const model = defineModel<string>({ required: true });

const normalized = computed(() => normalizeLinkName(model.value));

const status = computed((): string | undefined => {
    if (normalized.value && normalized.value !== model.value.trim()) {
        return `Matches links named ${normalized.value}`;
    }

    return model.value.trim() === '' ? 'Enter a link name.' : undefined;
});
</script>

<template>
    <div class="grid gap-1">
        <input
            v-bind="$attrs"
            :id="id"
            v-model="model"
            type="text"
            aria-label="Link name"
            :list="`${id}-suggestions`"
            :maxlength="LINK_NAME_MAX_LENGTH"
            placeholder="black-friday-cta"
            autocomplete="off"
        />
        <datalist :id="`${id}-suggestions`">
            <option v-for="name in linkNames" :key="name" :value="name" />
        </datalist>
        <p v-if="status" class="text-xs text-muted-foreground">
            {{ status }}
        </p>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
