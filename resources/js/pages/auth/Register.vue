<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';

const detectTimezone = (): string => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC';
    } catch {
        return 'UTC';
    }
};

const timezone = ref<string>(detectTimezone());

onMounted(() => {
    timezone.value = detectTimezone();
});
</script>

<template>
    <AuthBase
        title="Create an account"
        description="Create your BYOMailer account."
    >
        <Head title="Register" />
        <div class="mx-auto w-full max-w-sm">
            <Form
                action="/register"
                method="post"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-6">
                    <input type="hidden" name="timezone" :value="timezone" />

                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="name"
                            name="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            :tabindex="2"
                            autocomplete="email"
                            name="email"
                            placeholder="email@example.com"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            :tabindex="3"
                            autocomplete="new-password"
                            name="password"
                            placeholder="Password"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <Button
                        type="submit"
                        class="mt-2 w-full"
                        tabindex="4"
                        :disabled="processing"
                        data-test="register-user-button"
                    >
                        <Spinner v-if="processing" />
                        Create account
                    </Button>
                </div>

                <div class="text-center text-sm text-muted-foreground">
                    Already have an account?
                    <TextLink
                        :href="login()"
                        class="underline underline-offset-4"
                        :tabindex="5"
                        >Log in</TextLink
                    >
                </div>
            </Form>
        </div>
    </AuthBase>
</template>
