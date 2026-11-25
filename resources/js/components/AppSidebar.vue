<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    PhBookOpen,
    PhClipboardText,
    PhEnvelopeSimple,
    PhFileText,
    PhFlowArrow,
    PhFolders,
    PhFunnel,
    PhGitBranch,
    PhListChecks,
    PhPackage,
    PhShieldCheck,
    PhSlidersHorizontal,
    PhSquaresFour,
    PhTag,
    PhUserGear,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { computed } from 'vue';

import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type AppPageProps, type NavItem } from '@/types';

import AppLogo from './AppLogo.vue';

const page = usePage<AppPageProps>();
const iconMap = {
    dashboard: PhSquaresFour,
    reputation: PhShieldCheck,
    lists: PhListChecks,
    contacts: PhUsersThree,
    tags: PhTag,
    segments: PhFunnel,
    attributes: PhSlidersHorizontal,
    newsletters: PhEnvelopeSimple,
    sequences: PhBookOpen,
    automations: PhFlowArrow,
    'visual-automations': PhGitBranch,
    projects: PhFolders,
    blocks: PhPackage,
    'email-templates': PhFileText,
    'email-logs': PhClipboardText,
    admin: PhUserGear,
};

const mainNavItems = computed<NavItem[]>(() => {
    const navItems = page.props.navigation?.main ?? [];

    return navItems.map((item) => ({
        title: item.title,
        href: item.href,
        icon: iconMap[item.key as keyof typeof iconMap] ?? PhSquaresFour,
        group: item.group,
    }));
});

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
