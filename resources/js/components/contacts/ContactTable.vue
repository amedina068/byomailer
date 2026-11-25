<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Checkbox } from '@/components/ui/checkbox';
import { show as contactShow } from '@/routes/contacts';

export interface ContactRow {
    id: number;
    name?: string | null;
    email: string;
    unsubscribed_at?: string | null;
    created_at: string;
}

const props = defineProps<{
    contacts: ContactRow[];
    selectable?: boolean;
}>();

const selected = defineModel<number[]>('selected', { default: () => [] });

const formatDate = (value?: string | null): string =>
    value ? new Date(value).toLocaleDateString() : '—';

const navigateToContact = (contactId: number): void => {
    router.visit(contactShow(contactId).url);
};

const isSelected = (contactId: number): boolean =>
    selected.value.includes(contactId);

const toggle = (contactId: number): void => {
    if (isSelected(contactId)) {
        selected.value = selected.value.filter((id) => id !== contactId);
    } else {
        selected.value = [...selected.value, contactId];
    }
};

const allSelected = computed(
    () =>
        props.contacts.length > 0 &&
        props.contacts.every((contact) => isSelected(contact.id)),
);

const toggleAll = (): void => {
    if (allSelected.value) {
        const pageIds = props.contacts.map((contact) => contact.id);
        selected.value = selected.value.filter((id) => !pageIds.includes(id));
    } else {
        const merged = new Set([
            ...selected.value,
            ...props.contacts.map((contact) => contact.id),
        ]);
        selected.value = [...merged];
    }
};

const columnCount = computed(() => (props.selectable ? 3 : 2));
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border">
        <table class="min-w-full divide-y divide-border text-sm">
            <thead
                class="bg-muted/50 text-left tracking-wide text-muted-foreground uppercase"
            >
                <tr>
                    <th v-if="selectable" class="w-10 px-4 py-3">
                        <Checkbox
                            :model-value="allSelected"
                            aria-label="Select all contacts on this page"
                            @update:model-value="toggleAll"
                        />
                    </th>
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="contact in contacts"
                    :key="contact.id"
                    class="cursor-pointer bg-card/50 transition hover:bg-muted/30"
                    :class="{
                        'bg-primary/5': selectable && isSelected(contact.id),
                    }"
                    @click="navigateToContact(contact.id)"
                >
                    <td v-if="selectable" class="px-4 py-3" @click.stop>
                        <Checkbox
                            :model-value="isSelected(contact.id)"
                            :aria-label="`Select ${contact.email}`"
                            @update:model-value="toggle(contact.id)"
                        />
                    </td>
                    <td class="px-4 py-3">
                        <Link
                            :href="contactShow(contact.id)"
                            class="rounded font-medium text-foreground focus-visible:ring-1 focus-visible:ring-[var(--bm-accent)] focus-visible:outline-none"
                            @click.stop
                            >{{ contact.email }}</Link
                        >
                        <span
                            v-if="contact.unsubscribed_at"
                            class="ml-2 inline-flex items-center gap-1 rounded bg-red-500/10 px-1.5 py-0.5 align-middle font-mono text-[10px] font-medium text-red-700 dark:text-red-400"
                        >
                            <span class="size-1.5 rounded-full bg-red-500" />
                            Unsubscribed
                        </span>
                        <div class="text-xs text-muted-foreground">
                            {{ contact.name || '—' }}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                        {{ formatDate(contact.created_at) }}
                    </td>
                </tr>
                <tr v-if="contacts.length === 0">
                    <td
                        :colspan="columnCount"
                        class="px-4 py-6 text-center text-muted-foreground"
                    >
                        <slot name="empty">No contacts found.</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
