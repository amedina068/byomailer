<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import { store } from '@/actions/App/Http/Controllers/TagController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const emit = defineEmits<{
    success: [];
}>();

const storeRoute = store();

const handleSuccess = () => {
    emit('success');
};
</script>

<template>
    <Form
        :action="storeRoute.url"
        :method="storeRoute.method"
        class="space-y-6"
        v-slot="{ errors, processing }"
        @success="handleSuccess"
    >
        <div class="grid gap-2">
            <label for="tag-name" class="text-sm font-medium text-foreground">
                Tag name
            </label>
            <input
                id="tag-name"
                name="name"
                type="text"
                placeholder="VIP"
                required
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="errors.name" />
            <p class="text-xs text-muted-foreground">
                Tags are lightweight labels for organizing and filtering
                contacts.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="mr-2" />
                Create tag
            </Button>
        </div>
    </Form>
</template>
