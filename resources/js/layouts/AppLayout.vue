<script setup lang="ts">
import AppToaster from '@/components/AppToaster.vue';
import OnboardingChecklist from '@/components/OnboardingChecklist.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <slot />
        </div>
    </AppLayout>
    <AppToaster />
    <OnboardingChecklist />
</template>
