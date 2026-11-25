<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Code, Mail, Menu, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import OgImageMeta from '@/components/OgImageMeta.vue';
import { Button } from '@/components/ui/button';
import type {
    ActiveSection,
    NavItem,
} from '@/composables/useDocumentationSidebar';
import { dashboard, home, login } from '@/routes';

const props = defineProps<{
    activeSection: ActiveSection;
    sidebarItems: NavItem[];
}>();

const mobileMenuOpen = ref(false);
const page = usePage();
const canonicalPath = computed(() => page.url.split(/[?#]/)[0]);

interface NavGroup {
    label: string | null;
    items: NavItem[];
}

const groupedSidebarItems = computed<NavGroup[]>(() => {
    const groups: NavGroup[] = [];
    for (const item of props.sidebarItems) {
        const label = item.group ?? null;
        const last = groups[groups.length - 1];
        if (last && last.label === label) {
            last.items.push(item);
        } else {
            groups.push({ label, items: [item] });
        }
    }
    return groups;
});
</script>

<template>
    <div class="min-h-screen overflow-x-hidden bg-background">
        <Head>
            <link head-key="canonical" rel="canonical" :href="canonicalPath" />
        </Head>
        <OgImageMeta />

        <!-- Header -->
        <header
            class="sticky top-0 z-50 border-b bg-background/95 backdrop-blur"
        >
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center gap-2">
                    <Link :href="home()" class="flex items-center gap-2">
                        <Mail class="h-6 w-6 text-primary" />
                        <span class="text-xl font-semibold">BYOMailer</span>
                    </Link>
                </div>
                <nav class="flex items-center gap-4">
                    <Link v-if="$page.props.auth.user" :href="dashboard()">
                        <Button variant="outline">Dashboard</Button>
                    </Link>
                    <Link v-else :href="login()">
                        <Button variant="outline">Log in</Button>
                    </Link>
                </nav>
            </div>

            <!-- Section Tabs -->
            <div class="flex items-center border-t px-6">
                <div class="flex gap-1">
                    <Link
                        href="/documentation/guides/getting-started"
                        class="flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            props.activeSection === 'guides'
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                    >
                        <BookOpen class="h-4 w-4" />
                        Guides
                    </Link>
                    <Link
                        href="/documentation/api"
                        class="flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            props.activeSection === 'api'
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                    >
                        <Code class="h-4 w-4" />
                        REST API
                    </Link>
                </div>

                <!-- Mobile menu toggle -->
                <button
                    class="ml-auto p-2 text-muted-foreground hover:text-foreground lg:hidden"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <X v-if="mobileMenuOpen" class="h-5 w-5" />
                    <Menu v-else class="h-5 w-5" />
                </button>
            </div>

            <!-- Mobile sidebar -->
            <div
                v-if="mobileMenuOpen"
                class="border-t bg-background px-6 py-4 lg:hidden"
            >
                <nav class="space-y-4">
                    <div
                        v-for="(group, groupIndex) in groupedSidebarItems"
                        :key="group.label ?? `group-${groupIndex}`"
                    >
                        <p
                            v-if="group.label"
                            class="px-3 pb-2 font-mono text-[0.625rem] font-semibold tracking-[0.12em] text-muted-foreground/70 uppercase"
                        >
                            {{ group.label }}
                        </p>
                        <div class="space-y-1">
                            <div v-for="item in group.items" :key="item.title">
                                <Link
                                    :href="item.href"
                                    class="block rounded-md px-3 py-2 text-sm transition-colors hover:bg-muted"
                                    :class="{
                                        'bg-muted font-medium text-foreground':
                                            item.active,
                                        'text-muted-foreground': !item.active,
                                    }"
                                    @click="mobileMenuOpen = false"
                                >
                                    {{ item.title }}
                                </Link>
                                <div
                                    v-if="item.children && item.active"
                                    class="mt-1 ml-3 space-y-0.5 border-l pl-3"
                                >
                                    <a
                                        v-for="child in item.children"
                                        :key="child.title"
                                        :href="child.href"
                                        class="block rounded-md px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        @click="mobileMenuOpen = false"
                                    >
                                        {{ child.title }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <div class="overflow-hidden px-6 pb-8">
            <div
                class="min-w-0 lg:grid lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-10"
            >
                <!-- Sidebar Navigation -->
                <aside class="hidden border-r pr-6 lg:block">
                    <nav class="sticky space-y-6 pt-8">
                        <div
                            v-for="(group, groupIndex) in groupedSidebarItems"
                            :key="group.label ?? `group-${groupIndex}`"
                        >
                            <p
                                v-if="group.label"
                                class="px-3 pb-2 font-mono text-[0.625rem] font-semibold tracking-[0.12em] text-muted-foreground/70 uppercase"
                            >
                                {{ group.label }}
                            </p>
                            <div class="space-y-1">
                                <div
                                    v-for="item in group.items"
                                    :key="item.title"
                                >
                                    <Link
                                        :href="item.href"
                                        class="block rounded-md px-3 py-2 text-sm transition-colors hover:bg-muted"
                                        :class="{
                                            'bg-muted font-medium text-foreground':
                                                item.active,
                                            'text-muted-foreground':
                                                !item.active,
                                        }"
                                    >
                                        {{ item.title }}
                                    </Link>
                                    <div
                                        v-if="item.children && item.active"
                                        class="mt-1 ml-3 space-y-0.5 border-l pl-3"
                                    >
                                        <a
                                            v-for="child in item.children"
                                            :key="child.title"
                                            :href="child.href"
                                            class="block rounded-md px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        >
                                            {{ child.title }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </nav>
                </aside>

                <!-- Main Content -->
                <main class="min-w-0">
                    <div class="pt-8">
                        <slot />
                    </div>
                </main>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t py-8">
            <div
                class="flex flex-col items-center justify-between gap-4 px-6 text-sm text-muted-foreground sm:flex-row"
            >
                <div class="flex items-center gap-2">
                    <Mail class="h-4 w-4" />
                    <span>BYOMailer</span>
                </div>
                <p>
                    &copy; {{ new Date().getFullYear() }} All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
