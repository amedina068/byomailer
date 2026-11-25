<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { index as emailCredentials } from '@/routes/email-credentials';
import { index as emailIdentities } from '@/routes/identities';
import { edit as editProfile } from '@/routes/profile';
import { index as storageSetting } from '@/routes/storage-setting';
import { index as teamIndex } from '@/routes/team';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { index as webhooks } from '@/routes/webhooks';
import { type NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Password',
        href: editPassword(),
    },
    {
        title: 'Two-Factor Auth',
        href: show(),
    },
    {
        title: 'Email identities',
        href: emailIdentities(),
    },
    {
        title: 'AWS credentials',
        href: emailCredentials(),
    },
    {
        title: 'Storage',
        href: storageSetting(),
    },
    {
        title: 'Team',
        href: teamIndex(),
    },
    {
        title: 'API Tokens',
        href: apiTokens(),
    },
    {
        title: 'Webhooks',
        href: webhooks(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

// The webhook pages refuse a user who is not an admin.
const visibleNavItems = computed(() =>
    sidebarNavItems.filter(
        (item) => isAdmin.value || toUrl(item.href) !== webhooks().url,
    ),
);

const { urlIsActive } = useActiveUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in visibleNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': urlIsActive(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
