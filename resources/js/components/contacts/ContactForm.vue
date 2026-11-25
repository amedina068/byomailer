<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import ContactController from '@/actions/App/Http/Controllers/ContactController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const storeRoute = ContactController.store();
</script>

<template>
    <Form
        :action="storeRoute.url"
        :method="storeRoute.method"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <label for="email" class="text-sm font-medium text-foreground">
                Email address
                <span class="text-destructive">*</span>
            </label>
            <input
                id="email"
                name="email"
                type="email"
                placeholder="subscriber@example.com"
                required
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <label for="name" class="text-sm font-medium text-foreground">
                Name
                <span class="text-muted-foreground">(optional)</span>
            </label>
            <input
                id="name"
                name="name"
                type="text"
                placeholder="John Doe"
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="mr-2" />
                Create contact
            </Button>
        </div>
    </Form>
</template>
