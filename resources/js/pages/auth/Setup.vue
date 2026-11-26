<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

interface SetupUrlCommand {
    label: string;
    command: string;
}

defineProps<{
    token: string;
    tokenIsValid: boolean;
    tokenWasWrong: boolean;
    setupUrlCommands: SetupUrlCommand[];
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
        v-if="!tokenIsValid"
        title="Find the setup URL"
        description="This instance has no account yet. To create the owner account, open the setup URL. The URL holds a token that proves that you operate this server."
    >
        <Head title="Set up" />

        <div class="mx-auto flex w-full max-w-sm flex-col gap-6">
            <p
                v-if="tokenWasWrong"
                class="text-sm font-medium text-red-600 dark:text-red-500"
                role="alert"
            >
                The token in this URL is not correct.
            </p>

            <div
                class="rounded-md border bg-muted/40 p-4 text-sm text-muted-foreground"
            >
                <p>
                    On the server, run the command for your install. The output
                    shows the setup URL.
                </p>
                <dl class="mt-3 space-y-3">
                    <div v-for="item in setupUrlCommands" :key="item.command">
                        <dt class="text-xs">{{ item.label }}</dt>
                        <dd>
                            <code class="font-mono text-foreground">{{
                                item.command
                            }}</code>
                        </dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs">
                    If your container has another name, use that name. In a
                    container, put
                    <code class="font-mono">docker exec &lt;container&gt;</code>
                    before the artisan command.
                </p>
            </div>

            <Form action="/setup" method="get" class="flex flex-col gap-4">
                <div class="grid gap-2">
                    <Label for="token">Setup token or setup URL</Label>
                    <Input
                        id="token"
                        type="text"
                        name="token"
                        required
                        autofocus
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono"
                    />
                </div>
                <Button type="submit" class="w-full" data-test="token-button">
                    Continue
                </Button>
            </Form>
        </div>
    </AuthBase>

    <AuthBase
        v-else
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
                <input type="hidden" name="token" :value="token" />
                <InputError :message="errors.token" />

                <div class="grid gap-6">
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
