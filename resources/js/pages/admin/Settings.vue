<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import AdminNav from '@/components/admin/AdminNav.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit, update } from '@/routes/admin/settings';
import { index as usersIndex } from '@/routes/admin/users';
import type { BreadcrumbItem } from '@/types';

type RegistrationMode = 'open' | 'invite_only';

const props = defineProps<{
    registrationMode: RegistrationMode;
    defaultMode: RegistrationMode;
    modeIsStored: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: usersIndex().url },
    { title: 'Settings', href: edit().url },
];

const modes: { value: RegistrationMode; title: string; description: string }[] =
    [
        {
            value: 'invite_only',
            title: 'Invite only',
            description:
                'The sign-up page is closed. A team admin invites each new user to an account.',
        },
        {
            value: 'open',
            title: 'Open',
            description:
                'Anyone can sign up on /register. Each new user gets a new account. Team invites also work.',
        },
    ];

const modeTitle = (value: RegistrationMode): string =>
    modes.find((mode) => mode.value === value)?.title ?? value;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Admin: Settings" />

        <section class="space-y-6">
            <div class="space-y-4">
                <div class="space-y-1">
                    <h1
                        class="text-xl font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Admin
                    </h1>
                    <p class="text-sm" style="color: var(--bm-text-3)">
                        The settings of this BYOMailer instance.
                    </p>
                </div>
                <AdminNav active="settings" />
            </div>

            <div
                class="max-w-2xl overflow-hidden rounded-[6px] border bg-card"
                style="
                    border-color: var(--bm-border);
                    box-shadow: var(--bm-shadow-xs);
                "
            >
                <div
                    class="border-b px-4 py-3"
                    style="border-color: var(--bm-border)"
                >
                    <h2
                        class="text-xs font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        Registration
                    </h2>
                </div>

                <Form
                    v-bind="update.form()"
                    v-slot="{ errors, processing }"
                    class="space-y-4 p-4"
                >
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Registration mode</legend>
                        <label
                            v-for="mode in modes"
                            :key="mode.value"
                            class="flex cursor-pointer gap-3 rounded-[6px] border p-3 transition-colors duration-150 has-[:checked]:border-[var(--bm-text-1)] has-[:checked]:bg-[var(--bm-surface-2)]"
                            style="border-color: var(--bm-border)"
                        >
                            <input
                                type="radio"
                                name="registration_mode"
                                :value="mode.value"
                                :checked="props.registrationMode === mode.value"
                                class="mt-1"
                                :data-test="`registration-mode-${mode.value}`"
                            />
                            <span class="space-y-0.5">
                                <span
                                    class="block text-sm font-medium"
                                    style="color: var(--bm-text-1)"
                                >
                                    {{ mode.title }}
                                </span>
                                <span
                                    class="block text-xs"
                                    style="color: var(--bm-text-3)"
                                >
                                    {{ mode.description }}
                                </span>
                            </span>
                        </label>
                    </fieldset>
                    <InputError :message="errors.registration_mode" />

                    <p class="text-xs" style="color: var(--bm-text-3)">
                        <template v-if="modeIsStored">
                            You set this mode here. It overrides the
                            <code class="font-mono">REGISTRATION_ENABLED</code>
                            variable.
                        </template>
                        <template v-else>
                            No mode is saved yet, so
                            <code class="font-mono">REGISTRATION_ENABLED</code>
                            gives the mode: {{ modeTitle(defaultMode) }}.
                        </template>
                    </p>

                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Save
                    </Button>
                </Form>
            </div>
        </section>
    </AppLayout>
</template>
