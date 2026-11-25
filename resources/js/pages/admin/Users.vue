<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { PhMagnifyingGlass, PhUsersThree } from '@phosphor-icons/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import AdminNav from '@/components/admin/AdminNav.vue';
import AdminUserRow, {
    type AdminUser,
} from '@/components/admin/AdminUserRow.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as usersIndex } from '@/routes/admin/users';
import {
    store as deactivateUser,
    destroy as reactivateUser,
} from '@/routes/admin/users/deactivation';
import type { BreadcrumbItem } from '@/types';

interface PaginatedUsers {
    data: AdminUser[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    next_page_url: string | null;
    prev_page_url: string | null;
}

const props = defineProps<{
    users: PaginatedUsers;
    search: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: usersIndex().url },
    { title: 'Users', href: usersIndex().url },
];

const searchTerm = ref(props.search);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchTerm, (value) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        router.get(
            usersIndex().url,
            value.trim() === '' ? {} : { search: value.trim() },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 250);
});

onBeforeUnmount(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});

const processingId = ref<number | null>(null);
const pendingDeactivation = ref<AdminUser | null>(null);
// The dialog title keeps the email while the dialog closes.
const dialogEmail = ref('');

watch(pendingDeactivation, (user) => {
    if (user) {
        dialogEmail.value = user.email;
    }
});
const confirmOpen = computed({
    get: () => pendingDeactivation.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingDeactivation.value = null;
        }
    },
});

const confirmDeactivation = (): void => {
    const user = pendingDeactivation.value;

    if (!user) {
        return;
    }

    processingId.value = user.id;
    router.post(
        deactivateUser(user.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processingId.value = null;
                pendingDeactivation.value = null;
            },
        },
    );
};

const reactivate = (user: AdminUser): void => {
    processingId.value = user.id;
    router.delete(reactivateUser(user.id).url, {
        preserveScroll: true,
        onFinish: () => {
            processingId.value = null;
        },
    });
};

const goToPage = (url: string | null): void => {
    if (url) {
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Admin: Users" />

        <section class="space-y-6">
            <div class="space-y-4">
                <div class="space-y-1">
                    <h1
                        class="text-xl font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Admin
                    </h1>
                    <p class="text-sm" style="color: var(--bm-text-3)">
                        Manage the users of this BYOMailer instance. A
                        deactivated user keeps all data, but cannot log in, use
                        an API token or the MCP server, or send email.
                    </p>
                </div>
                <AdminNav active="users" />
            </div>

            <div
                class="overflow-hidden rounded-[6px] border bg-card"
                style="
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                "
            >
                <div
                    class="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    style="border-color: var(--bm-border)"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="text-xs font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Users
                        </span>
                        <span
                            class="rounded-[3px] border px-1.5 font-mono text-[10px]"
                            style="
                                color: var(--bm-text-3);
                                background-color: var(--bm-base);
                                border-color: var(--bm-border);
                            "
                        >
                            {{ users.total }}
                        </span>
                    </div>
                    <label class="relative block w-full sm:w-72">
                        <span class="sr-only">Search by name or email</span>
                        <PhMagnifyingGlass
                            :size="14"
                            class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2"
                            style="color: var(--bm-text-3)"
                        />
                        <Input
                            v-model="searchTerm"
                            type="search"
                            placeholder="Search by name or email"
                            class="h-8 pl-8 text-sm"
                            data-test="admin-user-search"
                        />
                    </label>
                </div>

                <div v-if="users.data.length > 0" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead
                            class="border-b text-left text-[10px] font-semibold tracking-widest uppercase"
                            style="
                                background-color: var(--bm-surface-2);
                                color: var(--bm-text-3);
                                border-color: var(--bm-border-strong);
                            "
                        >
                            <tr>
                                <th scope="col" class="px-4 py-2.5">User</th>
                                <th scope="col" class="px-4 py-2.5">Account</th>
                                <th scope="col" class="px-4 py-2.5">Created</th>
                                <th scope="col" class="px-4 py-2.5">Status</th>
                                <th scope="col" class="px-4 py-2.5 text-right">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <AdminUserRow
                                v-for="user in users.data"
                                :key="user.id"
                                :user="user"
                                :processing="processingId === user.id"
                                @deactivate="pendingDeactivation = $event"
                                @reactivate="reactivate"
                            />
                        </tbody>
                    </table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-6 py-16 text-center"
                >
                    <PhUsersThree :size="32" style="color: var(--bm-text-4)" />
                    <p class="text-sm" style="color: var(--bm-text-3)">
                        No user matches "{{ search }}".
                    </p>
                </div>

                <div
                    v-if="users.last_page > 1"
                    class="flex items-center justify-between border-t px-4 py-3 text-xs"
                    style="
                        border-color: var(--bm-border);
                        color: var(--bm-text-3);
                    "
                >
                    <span class="font-mono">
                        {{ users.from }}–{{ users.to }} of {{ users.total }}
                    </span>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!users.prev_page_url"
                            @click="goToPage(users.prev_page_url)"
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!users.next_page_url"
                            @click="goToPage(users.next_page_url)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <ConfirmDialog
            v-model:is-open="confirmOpen"
            :title="`Deactivate ${dialogEmail}?`"
            description="The user cannot log in, use an API token or the MCP server, or send email. The data of the user stays. You can reactivate the user at any time."
            confirm-label="Deactivate"
            :processing="processingId !== null"
            @confirm="confirmDeactivation"
        />
    </AppLayout>
</template>
