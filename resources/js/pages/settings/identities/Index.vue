<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhEnvelopeSimple, PhKey, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';

import Heading from '@/components/Heading.vue';
import VerificationStatusBadge from '@/components/identities/VerificationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index as emailCredentialsIndex } from '@/routes/email-credentials';
import { create, index, show } from '@/routes/identities';
import { type BreadcrumbItem } from '@/types';

interface EmailIdentity {
    id: number;
    email: string;
    name?: string | null;
    domain?: string | null;
    identity_status?: string | null;
    dkim_status?: string | null;
    mail_from_status?: string | null;
    verified_at?: string | null;
    created_at?: string | null;
}

const props = defineProps<{
    identities: EmailIdentity[];
    hasCredential: boolean;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Email identities',
        href: index().url,
    },
];

const identityList = computed(() => props.identities ?? []);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Email identities" />

        <h1 class="sr-only">Email identities</h1>

        <SettingsLayout>
            <div class="flex flex-col gap-6">
                <div class="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Email identities"
                        description="Email addresses you can send from. Each email identity has its own tracking settings and DNS records."
                    />

                    <Button
                        v-if="identityList.length > 0 && hasCredential"
                        as-child
                        size="sm"
                        class="shrink-0 rounded-[var(--bm-radius-md)]"
                    >
                        <Link :href="create().url">
                            <PhPlus :size="14" weight="bold" class="mr-2" />
                            Add email identity
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="identityList.length === 0 && !hasCredential"
                    class="flex flex-col items-center gap-4 rounded-[var(--bm-radius-md)] border border-dashed px-6 py-16 text-center"
                    style="border-color: var(--bm-border)"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-[var(--bm-radius-md)]"
                        style="background-color: var(--bm-warning-light)"
                    >
                        <PhKey :size="24" style="color: var(--bm-warning)" />
                    </div>
                    <div class="space-y-1">
                        <p
                            class="text-base font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Add AWS credentials first
                        </p>
                        <p class="text-sm" style="color: var(--bm-text-3)">
                            BYOMailer sends through your own AWS SES account.
                            Connect your AWS credentials before you add an email
                            identity.
                        </p>
                    </div>
                    <Button as-child class="rounded-[var(--bm-radius-md)]">
                        <Link :href="emailCredentialsIndex().url">
                            <PhKey :size="14" weight="bold" class="mr-2" />
                            Add AWS credentials
                        </Link>
                    </Button>
                </div>

                <div
                    v-else-if="identityList.length === 0"
                    class="flex flex-col items-center gap-4 rounded-[var(--bm-radius-md)] border border-dashed px-6 py-16 text-center"
                    style="border-color: var(--bm-border)"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-[var(--bm-radius-md)]"
                        style="background-color: var(--bm-accent-bright-dim)"
                    >
                        <PhEnvelopeSimple
                            :size="24"
                            style="color: var(--bm-accent)"
                        />
                    </div>
                    <div class="space-y-1">
                        <p
                            class="text-base font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            No email identities yet
                        </p>
                        <p class="text-sm" style="color: var(--bm-text-3)">
                            Add your first email identity to send email through
                            your own AWS SES account.
                        </p>
                    </div>
                    <Button as-child class="rounded-[var(--bm-radius-md)]">
                        <Link :href="create().url">
                            <PhPlus :size="14" weight="bold" class="mr-2" />
                            Add email identity
                        </Link>
                    </Button>
                </div>

                <div
                    v-else
                    class="overflow-hidden rounded-[var(--bm-radius-md)] border bg-[var(--bm-surface)] shadow-[var(--bm-shadow-xs)]"
                    style="border-color: var(--bm-border)"
                >
                    <ul class="divide-y" style="border-color: var(--bm-border)">
                        <li
                            v-for="identity in identityList"
                            :key="identity.id"
                            class="transition-colors hover:bg-[var(--bm-surface-2)]"
                        >
                            <Link
                                :href="show({ emailIdentity: identity.id }).url"
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-medium"
                                        style="color: var(--bm-text-1)"
                                    >
                                        {{ identity.email }}
                                    </p>
                                    <p
                                        v-if="identity.name"
                                        class="truncate text-xs"
                                        style="color: var(--bm-text-3)"
                                    >
                                        {{ identity.name }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <VerificationStatusBadge
                                        :status="identity.identity_status"
                                    />
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
