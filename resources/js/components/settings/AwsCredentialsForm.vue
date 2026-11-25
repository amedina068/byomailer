<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

import EmailCredentialController from '@/actions/App/Http/Controllers/Settings/EmailCredentialController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    region: string;
    readOnly: boolean;
}>();

const accessKey = ref('');
const secretKey = ref('');
const region = ref(props.region);
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const editing = ref(false);

const submit = (): void => {
    processing.value = true;
    errors.value = {};

    router.post(
        EmailCredentialController.store.url(),
        {
            access_key: accessKey.value,
            secret_key: secretKey.value,
            region: region.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                accessKey.value = '';
                secretKey.value = '';
            },
            onError: (err) => {
                errors.value = err;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="mt-10 space-y-4">
        <Heading
            variant="small"
            title="Update Credentials"
            description="Replace your AWS credentials."
        />

        <div v-if="!editing" class="flex justify-start">
            <Button
                variant="outline"
                size="sm"
                :disabled="readOnly"
                @click="editing = true"
                >Edit Credentials</Button
            >
        </div>

        <form
            v-else
            class="space-y-4 rounded-lg border bg-card p-4 shadow-sm"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="access_key">Access Key ID</Label>
                <Input
                    id="access_key"
                    v-model="accessKey"
                    type="text"
                    autocomplete="off"
                    placeholder="AKIA..."
                    required
                />
                <InputError :message="errors.access_key" />
            </div>

            <div class="grid gap-2">
                <Label for="secret_key">Secret Access Key</Label>
                <Input
                    id="secret_key"
                    v-model="secretKey"
                    type="password"
                    autocomplete="off"
                    placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCY..."
                    required
                />
                <InputError :message="errors.secret_key" />
            </div>

            <div class="grid gap-2">
                <Label for="region">Region</Label>
                <Input
                    id="region"
                    v-model="region"
                    type="text"
                    autocomplete="off"
                    placeholder="us-east-1"
                    required
                />
                <InputError :message="errors.region" />
            </div>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="processing">
                    {{ processing ? 'Saving...' : 'Save Credentials' }}
                </Button>
                <Button type="button" variant="ghost" @click="editing = false"
                    >Cancel</Button
                >
            </div>
        </form>
    </div>
</template>
