<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { type NavItem } from '@/types';

interface NavGroup {
    label: string | null;
    items: NavItem[];
}

const props = defineProps<{
    items: NavItem[];
}>();

const { urlIsActive } = useActiveUrl();

const groups = computed<NavGroup[]>(() => {
    const out: NavGroup[] = [];
    for (const item of props.items) {
        const label = item.group ?? null;
        const last = out[out.length - 1];
        if (last && last.label === label) {
            last.items.push(item);
        } else {
            out.push({ label, items: [item] });
        }
    }
    return out;
});
</script>

<template>
    <SidebarGroup
        v-for="(group, idx) in groups"
        :key="group.label ?? `group-${idx}`"
        class="px-2 py-0"
    >
        <SidebarGroupLabel v-if="group.label">{{
            group.label
        }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="urlIsActive(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
