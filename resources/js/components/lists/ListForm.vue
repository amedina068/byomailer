<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import MailListController from '@/actions/App/Http/Controllers/MailListController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

interface Props {
    redirectTo?: string;
    returnBack?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    redirectTo: undefined,
    returnBack: false,
});

const emit = defineEmits<{
    success: [];
}>();

const storeRoute = MailListController.store();

const handleSuccess = () => {
    emit('success');
};
</script>

<template>
    <Form
        :action="storeRoute.url"
        :method="storeRoute.method"
        class="space-y-6"
        v-slot="{ errors, processing, recentlySuccessful }"
        @success="handleSuccess"
    >
        <input
            v-if="props.redirectTo"
            type="hidden"
            name="_redirect"
            :value="props.redirectTo"
        />
        <input
            v-if="props.returnBack"
            type="hidden"
            name="_return_back"
            value="1"
        />

        <div class="grid gap-2">
            <label for="name" class="text-sm font-medium text-foreground">
                List name
            </label>
            <input
                id="name"
                name="name"
                type="text"
                placeholder="VIP Customers"
                required
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="errors.name" />
            <p class="text-xs text-muted-foreground">
                Use descriptive names so teammates understand who belongs inside
                each list.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="mr-2" />
                Create list
            </Button>
            <p
                v-if="recentlySuccessful && !props.redirectTo"
                class="text-xs text-muted-foreground"
            >
                List created. Add contacts next or jump into automations.
            </p>
        </div>
    </Form>
</template>
