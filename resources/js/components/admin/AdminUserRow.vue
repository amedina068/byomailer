<script setup lang="ts">
import { computed } from 'vue';

import { Button } from '@/components/ui/button';

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    client_name: string | null;
    role_label: string | null;
    is_owner: boolean;
    is_current_user: boolean;
    deactivated_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    user: AdminUser;
    processing: boolean;
}>();

const emit = defineEmits<{
    deactivate: [user: AdminUser];
    reactivate: [user: AdminUser];
}>();

const isDeactivated = computed(() => props.user.deactivated_at !== null);

const formatDate = (value: string | null): string =>
    value ? new Date(value).toISOString().slice(0, 10) : '';
</script>

<template>
    <tr
        class="border-b transition-colors duration-150 hover:bg-[var(--bm-surface-2)]"
        style="border-color: var(--bm-border)"
        :data-test="`admin-user-${user.id}`"
    >
        <td class="px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="font-medium" style="color: var(--bm-text-1)">
                    {{ user.name }}
                </span>
                <span
                    v-if="user.is_owner"
                    class="inline-flex items-center gap-1 rounded-[3px] px-2 py-0.5 font-mono text-[10px] font-medium"
                    style="
                        color: var(--bm-info);
                        background-color: var(--bm-info-light);
                    "
                >
                    Owner
                </span>
                <span
                    v-if="user.is_current_user"
                    class="text-xs"
                    style="color: var(--bm-text-3)"
                >
                    (you)
                </span>
            </div>
            <div class="text-xs" style="color: var(--bm-text-3)">
                {{ user.email }}
            </div>
        </td>
        <td class="px-4 py-3" style="color: var(--bm-text-2)">
            {{ user.client_name ?? '—' }}
            <div
                v-if="user.role_label"
                class="text-xs"
                style="color: var(--bm-text-3)"
            >
                Team {{ user.role_label.toLowerCase() }}
            </div>
        </td>
        <td class="px-4 py-3 font-mono text-xs" style="color: var(--bm-text-2)">
            {{ formatDate(user.created_at) }}
        </td>
        <td class="px-4 py-3">
            <span
                class="inline-flex items-center gap-[5px] rounded-[3px] px-2 py-0.5 font-mono text-[10px] font-medium"
                :style="
                    isDeactivated
                        ? 'color: var(--bm-error); background-color: var(--bm-error-light)'
                        : 'color: var(--bm-success); background-color: var(--bm-success-light)'
                "
            >
                <span class="size-[5px] rounded-full bg-current" />
                {{ isDeactivated ? 'Deactivated' : 'Active' }}
            </span>
        </td>
        <td class="px-4 py-3 text-right">
            <Button
                v-if="isDeactivated"
                variant="secondary"
                size="sm"
                :disabled="processing"
                @click="emit('reactivate', user)"
            >
                Reactivate
            </Button>
            <Button
                v-else-if="!user.is_owner"
                variant="ghost"
                size="sm"
                class="text-destructive hover:text-destructive"
                :disabled="processing"
                @click="emit('deactivate', user)"
            >
                Deactivate
            </Button>
        </td>
    </tr>
</template>
