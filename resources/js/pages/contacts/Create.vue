<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import ContactForm from '@/components/contacts/ContactForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { create as contactsCreate, index as contactsIndex } from '@/routes/contacts';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Contacts',
        href: contactsIndex().url,
    },
    {
        title: 'Create contact',
        href: contactsCreate().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create contact" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Create contact
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Add a new contact to your mailing list.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="contactsIndex().url"> Back to contacts </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <ContactForm />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
