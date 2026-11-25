<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { edit as settingsEdit } from '@/routes/admin/settings';
import { index as usersIndex } from '@/routes/admin/users';

defineProps<{
    active: 'users' | 'settings';
}>();

const tabs = [
    { key: 'users', title: 'Users', href: usersIndex().url },
    { key: 'settings', title: 'Settings', href: settingsEdit().url },
] as const;
</script>

<template>
    <nav
        aria-label="Admin sections"
        class="flex gap-1 border-b"
        style="border-color: var(--bm-border)"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="active === tab.key ? 'page' : undefined"
            class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150"
            :class="
                active === tab.key
                    ? 'border-[var(--bm-text-1)] text-[var(--bm-text-1)]'
                    : 'border-transparent text-[var(--bm-text-3)] hover:text-[var(--bm-text-1)]'
            "
        >
            {{ tab.title }}
        </Link>
    </nav>
</template>
