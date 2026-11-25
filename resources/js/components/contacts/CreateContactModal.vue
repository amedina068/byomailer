<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';

import { store } from '@/actions/App/Http/Controllers/ContactController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

const isOpen = defineModel<boolean>('isOpen');

const storeRoute = store();
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div
                    class="mx-auto mb-3 w-auto rounded-full border border-border bg-card p-0.5 shadow-sm"
                >
                    <div
                        class="relative overflow-hidden rounded-full border border-border bg-muted p-2.5"
                    >
                        <UserPlus
                            class="relative z-20 size-6 text-foreground"
                        />
                    </div>
                </div>
                <DialogTitle class="text-center">
                    Create new contact
                </DialogTitle>
                <DialogDescription class="text-center">
                    Add a new subscribed contact by email address.
                </DialogDescription>
            </DialogHeader>

            <Form
                :action="storeRoute.url"
                :method="storeRoute.method"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <label
                        for="contact-email"
                        class="text-sm font-medium text-foreground"
                    >
                        Email address
                        <span class="text-destructive">*</span>
                    </label>
                    <input
                        id="contact-email"
                        name="email"
                        type="email"
                        placeholder="subscriber@example.com"
                        required
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" class="mr-2" />
                        Create contact
                    </Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
