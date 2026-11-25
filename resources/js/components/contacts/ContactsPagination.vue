<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    contacts: {
        links: PaginationLink[];
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const visit = (url: string | null): void => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};

const isPageLink = (link: PaginationLink): boolean =>
    !link.label.includes('Previous') && !link.label.includes('Next');
</script>

<template>
    <div
        v-if="contacts.last_page > 1"
        class="mt-4 flex flex-wrap items-center justify-between gap-3"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ contacts.from }} to {{ contacts.to }} of
            {{ contacts.total }} contacts
        </p>

        <div class="flex flex-wrap items-center gap-1">
            <Button
                variant="outline"
                size="sm"
                :disabled="!contacts.prev_page_url"
                @click="visit(contacts.prev_page_url)"
            >
                <ChevronLeft class="size-4" />
                <span class="sr-only">Previous</span>
            </Button>

            <template v-for="(link, index) in contacts.links" :key="index">
                <Button
                    v-if="isPageLink(link)"
                    variant="outline"
                    size="sm"
                    :class="{
                        'bg-primary text-primary-foreground hover:bg-primary/90':
                            link.active,
                    }"
                    :disabled="!link.url"
                    @click="visit(link.url)"
                >
                    {{ link.label }}
                </Button>
            </template>

            <Button
                variant="outline"
                size="sm"
                :disabled="!contacts.next_page_url"
                @click="visit(contacts.next_page_url)"
            >
                <ChevronRight class="size-4" />
                <span class="sr-only">Next</span>
            </Button>
        </div>
    </div>
</template>
