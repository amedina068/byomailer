<script setup lang="ts">
import { Deferred, Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { type BreadcrumbItem } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    timezones: string[];
    sunsetInactiveDays: number | null;
    postalAddress: string | null;
    sunsetPreview?: { count: number; days: number } | null;
    isInstanceOwner?: boolean;
}

const props = defineProps<Props>();
const availableTimezones = props.timezones;

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

const page = usePage();
const user = page.props.auth.user;
const selectedTimezone = ref<string>(page.props.auth.timezone ?? 'UTC');

const selectedSunsetDays = ref<string>(
    props.sunsetInactiveDays === null ? '' : String(props.sunsetInactiveDays),
);

const sunsetDayOptions = computed(() => {
    const presets = [30, 60, 90, 180, 365];

    if (
        props.sunsetInactiveDays !== null &&
        !presets.includes(props.sunsetInactiveDays)
    ) {
        presets.push(props.sunsetInactiveDays);
        presets.sort((a, b) => a - b);
    }

    return presets;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Profile settings" />

        <h1 class="sr-only">Profile Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Profile information"
                    description="Update your name and email address"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            class="mt-1 block w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError class="mt-2" :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError class="mt-2" :message="errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600"
                        >
                            A new verification link has been sent to your email
                            address.
                        </div>
                    </div>

                    <Heading
                        variant="small"
                        title="Team settings"
                        description="The time zone, the postal address and the sunset rule apply to the whole team."
                    />

                    <div class="grid gap-2">
                        <Label for="timezone">Timezone</Label>
                        <select
                            id="timezone"
                            name="timezone"
                            v-model="selectedTimezone"
                            class="mt-1 block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            :disabled="processing"
                            required
                        >
                            <option
                                v-for="timezone in availableTimezones"
                                :key="timezone"
                                :value="timezone"
                            >
                                {{ timezone }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            This timezone will be used when scheduling
                            newsletters and automations.
                        </p>
                        <InputError class="mt-2" :message="errors.timezone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="postal_address">Postal address</Label>
                        <textarea
                            id="postal_address"
                            name="postal_address"
                            rows="3"
                            maxlength="500"
                            :value="postalAddress ?? ''"
                            class="mt-1 block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            placeholder="Street, city, postal code, country"
                            :disabled="processing"
                        />
                        <p class="text-xs text-muted-foreground">
                            Laws such as CAN-SPAM ask for a postal address in
                            each marketing email. Put
                            <span v-pre class="font-mono">{{
                                postal_address
                            }}</span>
                            in the footer of your templates. This field is
                            optional. If it is empty, BYOMailer shows a warning
                            before you send.
                        </p>
                        <InputError
                            class="mt-2"
                            :message="errors.postal_address"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="sunset_inactive_days"
                            >Sunset inactive contacts</Label
                        >
                        <select
                            id="sunset_inactive_days"
                            name="sunset_inactive_days"
                            v-model="selectedSunsetDays"
                            class="mt-1 block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            :disabled="processing"
                        >
                            <option value="">Off</option>
                            <option
                                v-for="days in sunsetDayOptions"
                                :key="days"
                                :value="String(days)"
                            >
                                No clicks in {{ days }} days
                            </option>
                        </select>
                        <div class="space-y-1 text-xs text-muted-foreground">
                            <p>
                                A daily run at
                                <span class="font-mono">04:00</span> UTC stops
                                mailing contacts who have not clicked in this
                                period. Only clicks count. Opens do not count,
                                because Apple Mail opens every message.
                            </p>
                            <p>
                                A contact must get at least
                                <span class="font-mono">3</span> emails in the
                                period before it can be sunset.
                            </p>
                            <p>
                                Off stops new sunsets. It does not mail contacts
                                that are already sunset. Reactivate them from
                                the contact page.
                            </p>
                        </div>
                        <Deferred
                            v-if="sunsetInactiveDays !== null"
                            data="sunsetPreview"
                        >
                            <template #fallback>
                                <div
                                    class="h-5 w-full max-w-sm animate-pulse"
                                    style="
                                        background-color: var(--bm-surface-2);
                                        border-radius: var(--bm-radius);
                                    "
                                />
                            </template>
                            <p
                                v-if="sunsetPreview"
                                class="text-sm text-foreground"
                                data-test="sunset-preview"
                            >
                                The next daily run will stop mailing
                                <span class="font-mono">{{
                                    sunsetPreview.count.toLocaleString()
                                }}</span>
                                {{
                                    sunsetPreview.count === 1
                                        ? 'contact who has'
                                        : 'contacts who have'
                                }}
                                not clicked in
                                <span class="font-mono">{{
                                    sunsetPreview.days
                                }}</span>
                                days.
                            </p>
                        </Deferred>
                        <InputError
                            class="mt-2"
                            :message="errors.sunset_inactive_days"
                        />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                            >Save</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>

            <DeleteUser :is-instance-owner="isInstanceOwner" />
        </SettingsLayout>
    </AppLayout>
</template>
