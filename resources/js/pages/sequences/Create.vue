<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    create as sequenceCreate,
    index as sequencesIndex,
    store as sequenceStore,
} from '@/routes/sequences';
import type { BreadcrumbItem } from '@/types';

const form = useForm({
    name: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sequences',
        href: sequencesIndex().url,
    },
    {
        title: 'Create sequence',
        href: sequenceCreate().url,
    },
];

const submit = () => {
    form.post(sequenceStore().url);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create sequence" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-col gap-1">
                    <h1 class="text-2xl font-semibold text-foreground">
                        Create sequence
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Give your sequence a descriptive name to start adding
                        steps.
                    </p>
                </div>

                <form class="mt-6 space-y-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <label
                            for="name"
                            class="text-sm font-medium text-foreground"
                        >
                            Sequence name
                        </label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            name="name"
                            class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            placeholder="Onboarding Series"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="flex justify-end">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Creating...'
                                    : 'Create sequence'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </section>
    </AppLayout>
</template>
