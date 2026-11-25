<script setup lang="ts">
import { watch } from 'vue';

import InputError from '@/components/InputError.vue';

defineProps<{
    errors: Record<string, string | undefined>;
}>();

const operator = defineModel<'changed_to' | 'changed'>('operator', {
    required: true,
});
const value = defineModel<string>('value', { required: true });

watch(operator, (next) => {
    if (next === 'changed') {
        value.value = '';
    }
});
</script>

<template>
    <div class="grid gap-2">
        <div class="grid grid-cols-2 gap-2">
            <div>
                <select
                    name="condition[operator]"
                    aria-label="Change type"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    v-model="operator"
                >
                    <option value="changed_to">changes to</option>
                    <option value="changed">changes</option>
                </select>
                <InputError :message="errors['condition.operator']" />
            </div>
            <div v-if="operator === 'changed_to'">
                <input
                    name="condition[value]"
                    type="text"
                    aria-label="Attribute value"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                    placeholder="pro"
                    v-model="value"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Exact match, case-sensitive.
                </p>
                <InputError :message="errors['condition.value']" />
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Runs when a contact's attribute changes after this automation is
            live. Contacts that already have the value are not included.
        </p>
    </div>
</template>
