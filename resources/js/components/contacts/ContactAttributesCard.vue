<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    PhDatabase,
    PhPencilSimple,
    PhPlus,
    PhTrash,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { toast } from '@/composables/useToast';

const props = defineProps<{
    contactId: number;
    attributes: Record<string, unknown>;
    attributeNames: string[];
}>();

const isAddingAttribute = ref(false);
const useCustomAttributeName = ref(false);
const selectedAttributeName = ref('');
const editingAttributeKey = ref<string | null>(null);
const editingAttributeValue = ref('');
const attributeProcessing = ref(false);
const pendingRemovalKey = ref<string | null>(null);

const attributeForm = useForm({
    attribute_name: '',
    attribute_value: '',
});

const attributeEntries = computed(() => Object.entries(props.attributes));

const availableAttributeNames = computed(() =>
    props.attributeNames.filter((attr) => !(attr in props.attributes)),
);

const handleAttributeSelect = (value: string) => {
    if (value === '__custom__') {
        useCustomAttributeName.value = true;
        selectedAttributeName.value = '';
        attributeForm.attribute_name = '';
    } else {
        selectedAttributeName.value = value;
        attributeForm.attribute_name = value;
    }
};

const cancelAddingAttribute = () => {
    isAddingAttribute.value = false;
    useCustomAttributeName.value = false;
    selectedAttributeName.value = '';
    attributeForm.reset();
    attributeForm.clearErrors();
};

const submitAttribute = () => {
    attributeForm.post(`/contacts/${props.contactId}/attributes`, {
        preserveScroll: true,
        onSuccess: () => {
            attributeForm.reset();
            selectedAttributeName.value = '';
            useCustomAttributeName.value = false;
            isAddingAttribute.value = false;
            toast.success('Attribute added.');
        },
    });
};

const startEditingAttribute = (key: string, value: unknown) => {
    editingAttributeKey.value = key;
    editingAttributeValue.value = String(value ?? '');
};

const cancelEditingAttribute = () => {
    editingAttributeKey.value = null;
    editingAttributeValue.value = '';
};

const saveAttribute = (key: string) => {
    if (attributeProcessing.value) {
        return;
    }

    attributeProcessing.value = true;

    router.patch(
        `/contacts/${props.contactId}/attributes/${encodeURIComponent(key)}`,
        { attribute_value: editingAttributeValue.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingAttributeKey.value = null;
                editingAttributeValue.value = '';
                toast.success('Attribute updated.');
            },
            onFinish: () => {
                attributeProcessing.value = false;
            },
        },
    );
};

const closeRemoveDialog = (open: boolean | undefined) => {
    if (!open) {
        pendingRemovalKey.value = null;
    }
};

const removeAttribute = () => {
    const key = pendingRemovalKey.value;
    pendingRemovalKey.value = null;

    if (key === null) {
        return;
    }

    router.delete(
        `/contacts/${props.contactId}/attributes/${encodeURIComponent(key)}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Attribute removed.');
            },
        },
    );
};
</script>

<template>
    <section
        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
    >
        <header
            class="flex items-center justify-between border-b border-border px-4 py-3"
        >
            <div class="flex items-center gap-2">
                <PhDatabase :size="15" class="text-muted-foreground" />
                <h2 class="text-sm font-semibold text-foreground">
                    Attributes
                </h2>
                <span
                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                >
                    {{ attributeEntries.length }}
                </span>
            </div>
            <button
                type="button"
                class="rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                aria-label="Add attribute"
                @click="isAddingAttribute = !isAddingAttribute"
            >
                <PhPlus :size="15" />
            </button>
        </header>

        <div class="p-4">
            <div
                v-if="isAddingAttribute"
                class="rounded-md border border-border p-3"
            >
                <form class="space-y-3" @submit.prevent="submitAttribute">
                    <div class="grid gap-1">
                        <label
                            for="attr_name"
                            class="text-xs font-medium text-muted-foreground"
                        >
                            Attribute name
                        </label>
                        <select
                            v-if="
                                availableAttributeNames.length &&
                                !useCustomAttributeName
                            "
                            id="attr_name"
                            :value="selectedAttributeName"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @change="
                                handleAttributeSelect(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="" disabled>Select attribute</option>
                            <option
                                v-for="attr in availableAttributeNames"
                                :key="attr"
                                :value="attr"
                            >
                                {{ attr }}
                            </option>
                            <option value="__custom__">Custom...</option>
                        </select>
                        <input
                            v-else
                            id="attr_name"
                            v-model="attributeForm.attribute_name"
                            type="text"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            placeholder="e.g. company"
                        />
                        <InputError
                            :message="attributeForm.errors.attribute_name"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label
                            for="attr_value"
                            class="text-xs font-medium text-muted-foreground"
                        >
                            Value
                        </label>
                        <input
                            id="attr_value"
                            v-model="attributeForm.attribute_value"
                            type="text"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            placeholder="Attribute value"
                        />
                        <InputError
                            :message="attributeForm.errors.attribute_value"
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="cancelAddingAttribute"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="attributeForm.processing"
                        >
                            {{ attributeForm.processing ? 'Adding...' : 'Add' }}
                        </Button>
                    </div>
                </form>
            </div>

            <div
                v-if="attributeEntries.length"
                class="grid gap-2"
                :class="{ 'mt-3': isAddingAttribute }"
            >
                <div
                    v-for="[key, value] in attributeEntries"
                    :key="key"
                    class="group rounded-md px-3 py-2 text-sm transition hover:bg-muted/40"
                >
                    <div v-if="editingAttributeKey === key" class="space-y-2">
                        <p
                            class="font-mono text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ key }}
                        </p>
                        <form
                            class="flex items-center gap-2"
                            @submit.prevent="saveAttribute(key)"
                        >
                            <input
                                v-model="editingAttributeValue"
                                type="text"
                                :aria-label="`Value of ${key}`"
                                class="flex-1 rounded-md border border-border bg-background px-3 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :disabled="attributeProcessing"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="attributeProcessing"
                            >
                                {{ attributeProcessing ? 'Saving...' : 'Save' }}
                            </Button>
                            <button
                                type="button"
                                class="text-xs text-muted-foreground hover:text-foreground"
                                @click="cancelEditingAttribute"
                            >
                                Cancel
                            </button>
                        </form>
                    </div>
                    <div v-else class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p
                                class="font-mono text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ key }}
                            </p>
                            <p class="truncate font-medium text-foreground">
                                {{ value as string }}
                            </p>
                        </div>
                        <div
                            class="flex shrink-0 gap-1 transition sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
                        >
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                :aria-label="`Edit attribute ${key}`"
                                @click="startEditingAttribute(key, value)"
                            >
                                <PhPencilSimple :size="14" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Remove attribute ${key}`"
                                @click="pendingRemovalKey = key"
                            >
                                <PhTrash :size="14" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <p
                v-else-if="!isAddingAttribute"
                class="text-sm text-muted-foreground"
            >
                No attributes yet.
            </p>
        </div>

        <ConfirmDialog
            :is-open="pendingRemovalKey !== null"
            title="Remove attribute?"
            :description="`This deletes the attribute ${pendingRemovalKey} and its value from this contact. You cannot undo this.`"
            confirm-label="Remove attribute"
            @update:is-open="closeRemoveDialog"
            @confirm="removeAttribute"
        />
    </section>
</template>
