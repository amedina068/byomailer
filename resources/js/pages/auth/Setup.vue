<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

defineProps<{
    token: string;
    setupUrlCommand: string;
}>();

const detectTimezone = (): string => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC';
    } catch {
        return 'UTC';
    }
};

const timezone = ref<string>('UTC');

onMounted(() => {
    timezone.value = detectTimezone();
});
</script>

<template>
    <AuthBase
        title="Set up BYOMailer"
        description="Create the owner account of this instance. The owner manages the users and the registration settings."
    >
        <Head title="Set up" />

        <div class="mx-auto w-full max-w-sm">
            <Form
                action="/setup"
                method="post"
                :reset-on-success="['password', 'password_confirmation']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <input type="hidden" name="timezone" :value="timezone" />

                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="token">Setup token</Label>
                        <Input
                            id="token"
                            type="text"
                            name="token"
                            required
                            :default-value="token"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                        />
                        <p class="text-xs text-muted-foreground">
                            The setup URL in the log of the web container holds
                            the token. To show the URL again, run
                            <code class="font-mono">{{ setupUrlCommand }}</code
                            >.
                        </p>
                        <InputError :message="errors.token" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            type="text"
                            name="name"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autocomplete="email"
                            placeholder="email@example.com"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            required
                            autocomplete="new-password"
                            placeholder="Password"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">
                            Confirm password
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                            placeholder="Confirm password"
                        />
                    </div>

                    <Button
                        type="submit"
                        class="mt-2 w-full"
                        :disabled="processing"
                        data-test="setup-button"
                    >
                        <Spinner v-if="processing" />
                        Create the owner account
                    </Button>
                </div>
            </Form>
        </div>
    </AuthBase>
</template>
