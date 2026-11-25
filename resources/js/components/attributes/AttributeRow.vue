<script setup lang="ts">
import { PhPencilSimple } from '@phosphor-icons/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface AttributeListItem {
    id: number;
    name: string;
    default_value: string | null;
}

const props = defineProps<{
    attribute: AttributeListItem;
    saving: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    (e: 'save', value: string): void;
    (e: 'cancel'): void;
}>();

const isEditing = ref(false);
const draftValue = ref('');
const placeholderLabel = computed(() => `{{ ${props.attribute.name} }}`);
const errorId = computed(() => `attribute-${props.attribute.id}-error`);
const inputRef = useTemplateRef<InstanceType<typeof Input>>('inputRef');
const editButtonRef = useTemplateRef<InstanceType<typeof Button>>('editButtonRef');

const startEditing = async (): Promise<void> => {
    emit('cancel');
    draftValue.value = props.attribute.default_value ?? '';
    isEditing.value = true;
    await nextTick();
    inputRef.value?.$el?.focus();
};

const save = (): void => {
    emit('save', draftValue.value);
};

const closeAndRestoreFocus = async (): Promise<void> => {
    isEditing.value = false;
    await nextTick();
    editButtonRef.value?.$el?.focus();
};

const cancel = (): void => {
    emit('cancel');
    void closeAndRestoreFocus();
};

// Relies on Index.vue mutating errorById (onError) before savingId (onFinish)
// synchronously in the same tick, so props.error is settled when saving flips.
watch(
    () => props.saving,
    (nowSaving, wasSaving) => {
        if (wasSaving && !nowSaving && !props.error) {
            void closeAndRestoreFocus();
        }
    },
);
</script>

<template>
    <tr class="border-t border-sidebar-border/60 dark:border-sidebar-border">
        <td class="px-4 py-3">
            <code
                class="text-sm"
                style="font-family: var(--bm-font-mono); color: var(--bm-text-1)"
                v-text="placeholderLabel"
            ></code>
        </td>
        <td class="px-4 py-3">
            <form
                v-if="isEditing"
                class="flex flex-col gap-1"
                @submit.prevent="save"
            >
                <div class="flex items-center gap-2">
                    <Input
                        ref="inputRef"
                        v-model="draftValue"
                        type="text"
                        name="default_value"
                        maxlength="255"
                        class="h-8 max-w-xs text-sm"
                        :aria-label="`Default value for ${attribute.name}`"
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="error ? errorId : undefined"
                        :disabled="saving"
                        @keydown.esc="cancel"
                    />
                    <Button type="submit" size="sm" :disabled="saving">
                        {{ saving ? 'Saving…' : 'Save' }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="saving"
                        @click="cancel"
                    >
                        Cancel
                    </Button>
                </div>
                <p v-if="error" :id="errorId" class="text-xs text-red-400">
                    {{ error }}
                </p>
            </form>
            <span
                v-else-if="attribute.default_value !== null"
                class="text-sm"
                style="color: var(--bm-text-1)"
                >{{ attribute.default_value }}</span
            >
            <span v-else class="text-sm" style="color: var(--bm-text-4)"
                >No default</span
            >
        </td>
        <td class="px-4 py-3 text-right">
            <Button
                v-if="!isEditing"
                ref="editButtonRef"
                size="sm"
                variant="ghost"
                class="gap-1.5"
                @click="startEditing"
            >
                <PhPencilSimple :size="14" />
                Edit
            </Button>
        </td>
    </tr>
</template>
