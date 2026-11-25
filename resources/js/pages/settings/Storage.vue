<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { DatabaseIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { destroy, index, store, test } from '@/routes/storage-setting/index';
import { type BreadcrumbItem } from '@/types';

interface StorageSetting {
    id: number;
    provider: string;
    bucket: string;
    region: string;
    endpoint: string | null;
    public_base_url: string;
    path_prefix: string;
    is_verified: boolean;
    updated_at: string | null;
}

const props = defineProps<{
    setting?: StorageSetting | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Storage',
        href: index().url,
    },
];

const page = usePage();
const isViewer = computed(() => page.props.auth.user?.role === 'viewer');

const provider = ref(props.setting?.provider ?? 's3');
const bucket = ref(props.setting?.bucket ?? '');
const region = ref(props.setting?.region ?? '');
const endpoint = ref(props.setting?.endpoint ?? '');
const publicBaseUrl = ref(props.setting?.public_base_url ?? '');
const accessKeyId = ref('');
const secretAccessKey = ref('');
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const testing = ref(false);
const testResult = ref<{ success: boolean; error?: string } | null>(null);
const editing = ref(!props.setting && !isViewer.value);
const removing = ref(false);

const isR2 = computed(() => provider.value === 'r2');

watch(provider, () => {
    testResult.value = null;
});

const getCsrfToken = (): string => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

const submit = (): void => {
    processing.value = true;
    errors.value = {};
    testResult.value = null;

    router.post(
        store().url,
        {
            provider: provider.value,
            bucket: bucket.value,
            region: region.value,
            endpoint: isR2.value ? endpoint.value : null,
            public_base_url: publicBaseUrl.value,
            access_key_id: accessKeyId.value,
            secret_access_key: secretAccessKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                accessKeyId.value = '';
                secretAccessKey.value = '';
                editing.value = false;
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

const runTest = async (): Promise<void> => {
    testing.value = true;
    testResult.value = null;

    try {
        const response = await fetch(test().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });

        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, error: 'Connection test failed.' };
    } finally {
        testing.value = false;
    }
};

const remove = (): void => {
    if (
        !confirm(
            'Remove your storage configuration? Media already uploaded will remain in your bucket.',
        )
    ) {
        return;
    }

    removing.value = true;

    router.delete(destroy().url, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            editing.value = true;
            provider.value = 's3';
            bucket.value = '';
            region.value = '';
            endpoint.value = '';
            publicBaseUrl.value = '';
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Storage" />

        <h1 class="sr-only">Storage</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Bring Your Own Storage"
                    description="Store email media in your own S3-compatible bucket. Images in your emails will be served from your own domain."
                />

                <p v-if="isViewer" class="text-sm text-muted-foreground">
                    Viewers have read-only access and cannot change the storage.
                    Ask an admin to change your role.
                </p>

                <!-- Current Config -->
                <template v-if="setting && !editing">
                    <Alert
                        :variant="
                            setting.is_verified ? 'default' : 'destructive'
                        "
                    >
                        <AlertTitle class="flex items-center gap-2">
                            <span>Storage Configured</span>
                            <Badge
                                :variant="
                                    setting.is_verified
                                        ? 'default'
                                        : 'destructive'
                                "
                            >
                                {{
                                    setting.is_verified
                                        ? 'Verified'
                                        : 'Unverified'
                                }}
                            </Badge>
                        </AlertTitle>
                        <AlertDescription>
                            Provider:
                            <span class="font-medium uppercase">{{
                                setting.provider
                            }}</span
                            >. Bucket:
                            <span class="font-medium">{{ setting.bucket }}</span
                            >. Region:
                            <span class="font-medium">{{ setting.region }}</span
                            >.
                            <span v-if="setting.updated_at">
                                Last updated:
                                <span class="font-medium">{{
                                    new Date(
                                        setting.updated_at,
                                    ).toLocaleString()
                                }}</span
                                >.
                            </span>
                        </AlertDescription>
                    </Alert>

                    <div
                        class="space-y-1 rounded-lg border bg-card p-4 text-sm"
                    >
                        <p>
                            <span class="text-muted-foreground"
                                >Public Base URL:</span
                            >
                            <span class="ml-1 font-medium">{{
                                setting.public_base_url
                            }}</span>
                        </p>
                        <p>
                            <span class="text-muted-foreground"
                                >Path Prefix:</span
                            >
                            <span class="ml-1 font-medium">{{
                                setting.path_prefix
                            }}</span>
                        </p>
                    </div>

                    <!-- Test result after testing -->
                    <Alert
                        v-if="testResult"
                        :variant="
                            testResult.success ? 'default' : 'destructive'
                        "
                    >
                        <AlertTitle>{{
                            testResult.success
                                ? 'Connection Successful'
                                : 'Connection Failed'
                        }}</AlertTitle>
                        <AlertDescription v-if="!testResult.success">{{
                            testResult.error
                        }}</AlertDescription>
                    </Alert>

                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="isViewer"
                            @click="editing = true"
                            >Edit Settings</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="testing || isViewer"
                            @click="runTest"
                        >
                            {{ testing ? 'Testing…' : 'Test Connection' }}
                        </Button>
                        <Button
                            variant="destructive"
                            size="sm"
                            :disabled="removing || isViewer"
                            @click="remove"
                        >
                            Remove
                        </Button>
                    </div>
                </template>

                <!-- Empty State (no config yet) -->
                <template v-else-if="!setting && !editing">
                    <div
                        class="flex flex-col items-center justify-center py-12 text-center"
                    >
                        <div
                            class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted"
                        >
                            <DatabaseIcon
                                class="h-8 w-8 text-muted-foreground"
                            />
                        </div>
                        <h2 class="mb-2 text-lg font-semibold">
                            No Custom Storage
                        </h2>
                        <p class="mb-6 max-w-sm text-sm text-muted-foreground">
                            Media is stored on the default disk. Configure your
                            own S3 or R2 bucket to serve media from your domain.
                        </p>
                        <Button :disabled="isViewer" @click="editing = true"
                            >Configure Storage</Button
                        >
                    </div>
                </template>

                <!-- Form -->
                <form
                    v-if="editing"
                    class="space-y-5 rounded-lg border bg-card p-5 shadow-sm"
                    @submit.prevent="submit"
                >
                    <Alert v-if="errors.connection" variant="destructive">
                        <AlertTitle>Connection Failed</AlertTitle>
                        <AlertDescription>{{
                            errors.connection
                        }}</AlertDescription>
                    </Alert>

                    <!-- Provider -->
                    <div class="grid gap-2">
                        <Label for="provider">Provider</Label>
                        <select
                            id="provider"
                            v-model="provider"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <option value="s3">AWS S3</option>
                            <option value="r2">Cloudflare R2</option>
                        </select>
                        <InputError :message="errors.provider" />
                    </div>

                    <!-- Bucket -->
                    <div class="grid gap-2">
                        <Label for="bucket">Bucket Name</Label>
                        <Input
                            id="bucket"
                            v-model="bucket"
                            type="text"
                            autocomplete="off"
                            placeholder="my-media-bucket"
                            required
                        />
                        <InputError :message="errors.bucket" />
                    </div>

                    <!-- Region -->
                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <Input
                            id="region"
                            v-model="region"
                            type="text"
                            autocomplete="off"
                            :placeholder="isR2 ? 'auto' : 'us-east-1'"
                            required
                        />
                        <InputError :message="errors.region" />
                    </div>

                    <!-- Endpoint (R2 only) -->
                    <div v-if="isR2" class="grid gap-2">
                        <Label for="endpoint">Endpoint</Label>
                        <Input
                            id="endpoint"
                            v-model="endpoint"
                            type="url"
                            autocomplete="off"
                            placeholder="https://accountid.r2.cloudflarestorage.com"
                            :required="isR2"
                        />
                        <p class="text-xs text-muted-foreground">
                            Found in your R2 bucket settings under "S3 API".
                        </p>
                        <InputError :message="errors.endpoint" />
                    </div>

                    <!-- Public Base URL -->
                    <div class="grid gap-2">
                        <Label for="public_base_url">Public Base URL</Label>
                        <Input
                            id="public_base_url"
                            v-model="publicBaseUrl"
                            type="url"
                            autocomplete="off"
                            placeholder="https://media.yourdomain.com"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Must start with <code>https://</code>. This domain
                            is used in emails to serve images. For S3, enable
                            static website hosting or a CloudFront distribution.
                            For R2, configure a Custom Domain in your bucket.
                        </p>
                        <InputError :message="errors.public_base_url" />
                    </div>

                    <!-- Access Key ID -->
                    <div class="grid gap-2">
                        <Label for="access_key_id">Access Key ID</Label>
                        <Input
                            id="access_key_id"
                            v-model="accessKeyId"
                            type="text"
                            autocomplete="off"
                            placeholder="AKIA..."
                            required
                        />
                        <InputError :message="errors.access_key_id" />
                    </div>

                    <!-- Secret Access Key -->
                    <div class="grid gap-2">
                        <Label for="secret_access_key">Secret Access Key</Label>
                        <Input
                            id="secret_access_key"
                            v-model="secretAccessKey"
                            type="password"
                            autocomplete="off"
                            placeholder="Your secret key"
                            required
                        />
                        <InputError :message="errors.secret_access_key" />
                    </div>

                    <!-- Permissions help -->
                    <div
                        class="space-y-1 rounded-md bg-muted/60 p-3 text-xs text-muted-foreground"
                    >
                        <p class="font-medium text-foreground">
                            Required IAM / API permissions
                        </p>
                        <p v-if="!isR2">
                            <strong>AWS S3:</strong>
                            <code>s3:PutObject</code>,
                            <code>s3:GetObject</code>,
                            <code>s3:DeleteObject</code> on
                            <code>arn:aws:s3:::your-bucket/*</code>.
                        </p>
                        <p v-else>
                            <strong>Cloudflare R2:</strong> Create an API token
                            with <em>Object Read &amp; Write</em> permissions
                            scoped to your bucket.
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button type="submit" :disabled="processing">
                            {{
                                processing
                                    ? 'Saving & Verifying…'
                                    : 'Save & Verify'
                            }}
                        </Button>
                        <Button
                            v-if="setting"
                            type="button"
                            variant="ghost"
                            @click="editing = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
