<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircleIcon,
    CloudIcon,
    RefreshCwIcon,
    XCircleIcon,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

import AwsOnboardingController from '@/actions/App/Http/Controllers/Onboarding/AwsOnboardingController';
import EmailCredentialController from '@/actions/App/Http/Controllers/Settings/EmailCredentialController';
import Heading from '@/components/Heading.vue';
import AwsRoleConnect, {
    type RoleConnection,
} from '@/components/onboarding/AwsRoleConnect.vue';
import AwsCredentialsForm from '@/components/settings/AwsCredentialsForm.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Credential {
    id: number;
    type: string;
    region: string;
    access_key_hint?: string | null;
    uses_role?: boolean;
    role_arn?: string | null;
    can_create_template?: boolean;
    can_delete_template?: boolean;
    can_send_bulk_email?: boolean;
    can_send_email?: boolean;
    permissions_checked_at?: string | null;
    production_access_enabled?: boolean;
    sending_enabled?: boolean;
    max_24_hour_send?: number;
    max_send_rate?: number;
    sent_last_24_hours?: number;
    quota_fetched_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

const props = defineProps<{
    credential?: Credential | null;
    roleConnection?: RoleConnection | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'AWS credentials',
        href: EmailCredentialController.index.url(),
    },
];

const page = usePage();
const isViewer = computed(() => page.props.auth.user?.role === 'viewer');

const refreshingQuota = ref(false);
const quotaError = ref('');

const usagePercentage = computed(() => {
    if (!props.credential?.max_24_hour_send) {
        return 0;
    }
    return Math.round(
        ((props.credential.sent_last_24_hours ?? 0) /
            props.credential.max_24_hour_send) *
            100,
    );
});

const formatNumber = (num: number | undefined): string => {
    if (num === undefined) {
        return '0';
    }
    return num.toLocaleString();
};

const refreshQuota = async (): Promise<void> => {
    refreshingQuota.value = true;
    quotaError.value = '';
    try {
        await axios.post(EmailCredentialController.refreshQuota.url());
        router.reload({ only: ['credential'] });
    } catch (error: unknown) {
        quotaError.value =
            (axios.isAxiosError(error) && error.response?.data?.error) ||
            'An error occurred while refreshing the quota.';
    } finally {
        refreshingQuota.value = false;
    }
};

const recheckingPermissions = ref(false);
const permissionError = ref('');

const recheckPermissions = async (): Promise<void> => {
    recheckingPermissions.value = true;
    permissionError.value = '';
    try {
        await axios.post(EmailCredentialController.recheckPermissions.url());
        router.reload({ only: ['credential'] });
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.error) {
            permissionError.value = error.response.data.error;
        } else {
            permissionError.value =
                'An error occurred while checking permissions.';
        }
    } finally {
        recheckingPermissions.value = false;
    }
};

const permissionItems = computed(() => [
    {
        label: 'Create Email Template',
        action: 'ses:CreateEmailTemplate',
        granted: props.credential?.can_create_template ?? false,
    },
    {
        label: 'Delete Email Template',
        action: 'ses:DeleteEmailTemplate',
        granted: props.credential?.can_delete_template ?? false,
    },
    {
        label: 'Send Bulk Email',
        action: 'ses:SendBulkEmail',
        granted: props.credential?.can_send_bulk_email ?? false,
    },
    {
        label: 'Send Email',
        action: 'ses:SendEmail',
        granted: props.credential?.can_send_email ?? false,
    },
]);

const allPermissionsGranted = computed(() =>
    permissionItems.value.every((p) => p.granted),
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="AWS credentials" />

        <h1 class="sr-only">AWS credentials</h1>

        <SettingsLayout>
            <!-- Empty State when no credentials -->
            <div
                v-if="!credential"
                class="flex flex-col items-center justify-center py-12 text-center"
            >
                <div
                    class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted"
                >
                    <CloudIcon class="h-8 w-8 text-muted-foreground" />
                </div>
                <h2 class="mb-2 text-lg font-semibold">No AWS Credentials</h2>
                <p class="mb-6 max-w-sm text-sm text-muted-foreground">
                    Get started by connecting your AWS account to send emails
                    through Amazon SES.
                </p>
                <Button as-child>
                    <Link :href="AwsOnboardingController.show.url()"
                        >Set up AWS credentials</Link
                    >
                </Button>
            </div>

            <!-- Existing credentials UI -->
            <template v-else>
                <div class="mt-10 space-y-4">
                    <Heading
                        variant="small"
                        title="Current Status"
                        :description="
                            credential.uses_role
                                ? 'BYOMailer assumes the IAM role of your AWS account and keeps no keys.'
                                : 'Your keys are stored encrypted in the database.'
                        "
                    />

                    <p v-if="isViewer" class="text-sm text-muted-foreground">
                        Viewers have read-only access and cannot change the AWS
                        credentials. Ask an admin to change your role.
                    </p>

                    <Alert>
                        <AlertTitle>Credentials configured</AlertTitle>
                        <AlertDescription>
                            Region:
                            <span class="font-medium">{{
                                credential.region
                            }}</span
                            >. Last updated:
                            <span class="font-medium">
                                {{
                                    credential.updated_at
                                        ? new Date(
                                              credential.updated_at,
                                          ).toLocaleString()
                                        : '—'
                                }} </span
                            >.
                            <template v-if="credential.uses_role">
                                IAM role
                                <span class="font-mono text-xs">{{
                                    credential.role_arn
                                }}</span>
                            </template>
                            <template v-else>
                                Access key ending in
                                <span class="font-medium">{{
                                    credential.access_key_hint ?? '••••'
                                }}</span>
                            </template>
                        </AlertDescription>
                    </Alert>
                </div>

                <!-- SES Quota Section -->
                <div class="mt-10 space-y-4">
                    <div class="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="SES Sending Quota"
                            description="Your current AWS SES sending limits and usage."
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="refreshingQuota || isViewer"
                            @click="refreshQuota"
                        >
                            <RefreshCwIcon
                                class="mr-2 h-4 w-4"
                                :class="{ 'animate-spin': refreshingQuota }"
                            />
                            Refresh
                        </Button>
                    </div>

                    <div class="rounded-lg border bg-card p-6">
                        <div class="mb-4 flex items-center gap-2">
                            <Badge
                                :variant="
                                    credential.production_access_enabled
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    credential.production_access_enabled
                                        ? 'Production'
                                        : 'Sandbox'
                                }}
                            </Badge>
                            <Badge
                                :variant="
                                    credential.sending_enabled
                                        ? 'default'
                                        : 'destructive'
                                "
                            >
                                {{
                                    credential.sending_enabled
                                        ? 'Sending Enabled'
                                        : 'Sending Disabled'
                                }}
                            </Badge>
                        </div>

                        <div class="space-y-4">
                            <!-- Daily sending progress -->
                            <div>
                                <div
                                    class="mb-2 flex items-center justify-between text-sm"
                                >
                                    <span class="text-muted-foreground"
                                        >Emails sent today</span
                                    >
                                    <span class="font-medium">
                                        {{
                                            formatNumber(
                                                credential.sent_last_24_hours,
                                            )
                                        }}
                                        /
                                        {{
                                            formatNumber(
                                                credential.max_24_hour_send,
                                            )
                                        }}
                                        <span class="text-muted-foreground"
                                            >({{ usagePercentage }}%)</span
                                        >
                                    </span>
                                </div>
                                <Progress
                                    :value="credential.sent_last_24_hours ?? 0"
                                    :max="credential.max_24_hour_send ?? 200"
                                />
                            </div>

                            <!-- Rate limit info -->
                            <div
                                class="grid grid-cols-2 gap-4 rounded-lg bg-muted/50 p-4"
                            >
                                <div>
                                    <div class="text-sm text-muted-foreground">
                                        Max Send Rate
                                    </div>
                                    <div class="text-lg font-semibold">
                                        {{
                                            formatNumber(
                                                credential.max_send_rate,
                                            )
                                        }}
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                            >/sec</span
                                        >
                                    </div>
                                </div>
                                <div>
                                    <div class="text-sm text-muted-foreground">
                                        Daily Limit
                                    </div>
                                    <div class="text-lg font-semibold">
                                        {{
                                            formatNumber(
                                                credential.max_24_hour_send,
                                            )
                                        }}
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                            >emails</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="credential.quota_fetched_at"
                                class="text-xs text-muted-foreground"
                            >
                                Last updated:
                                {{
                                    new Date(
                                        credential.quota_fetched_at,
                                    ).toLocaleString()
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <p v-if="quotaError" class="mt-2 text-sm text-destructive">
                    {{ quotaError }}
                </p>

                <!-- API Permissions Status -->
                <div class="mt-10 space-y-4">
                    <div class="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="API Permissions"
                            description="SES API permissions detected for your AWS credentials."
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="recheckingPermissions || isViewer"
                            @click="recheckPermissions"
                        >
                            <RefreshCwIcon
                                class="mr-2 h-4 w-4"
                                :class="{
                                    'animate-spin': recheckingPermissions,
                                }"
                            />
                            Recheck
                        </Button>
                    </div>

                    <div class="rounded-lg border bg-card p-6">
                        <div
                            v-if="credential.permissions_checked_at"
                            class="mb-4 flex items-center gap-2"
                        >
                            <Badge
                                :variant="
                                    allPermissionsGranted
                                        ? 'default'
                                        : 'destructive'
                                "
                            >
                                {{
                                    allPermissionsGranted
                                        ? 'All Granted'
                                        : 'Missing Permissions'
                                }}
                            </Badge>
                        </div>

                        <div
                            v-if="!credential.permissions_checked_at"
                            class="text-sm text-muted-foreground"
                        >
                            Permissions have not been checked yet. Click
                            "Recheck" to verify your API permissions.
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="permission in permissionItems"
                                :key="permission.action"
                                class="flex items-center justify-between rounded-lg bg-muted/50 px-4 py-3"
                            >
                                <div>
                                    <div class="text-sm font-medium">
                                        {{ permission.label }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ permission.action }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <CheckCircleIcon
                                        v-if="permission.granted"
                                        class="h-5 w-5 text-green-500"
                                    />
                                    <XCircleIcon
                                        v-else
                                        class="h-5 w-5 text-red-500"
                                    />
                                    <span
                                        class="text-sm font-medium"
                                        :class="
                                            permission.granted
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-red-600 dark:text-red-400'
                                        "
                                    >
                                        {{
                                            permission.granted
                                                ? 'Granted'
                                                : 'Denied'
                                        }}
                                    </span>
                                </div>
                            </div>

                            <p class="text-xs text-muted-foreground">
                                Last checked:
                                {{
                                    new Date(
                                        credential.permissions_checked_at,
                                    ).toLocaleString()
                                }}
                            </p>
                        </div>
                    </div>

                    <p v-if="permissionError" class="text-sm text-destructive">
                        {{ permissionError }}
                    </p>
                </div>

                <div
                    v-if="roleConnection && !credential.uses_role"
                    class="mt-10"
                >
                    <AwsRoleConnect
                        :role-connection="roleConnection"
                        :read-only="isViewer"
                    />
                </div>

                <AwsCredentialsForm
                    :region="credential.region"
                    :read-only="isViewer"
                />
            </template>
        </SettingsLayout>
    </AppLayout>
</template>
