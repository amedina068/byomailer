<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Copy, Download, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index } from '@/routes/api-tokens';
import { postman as documentationPostman } from '@/routes/documentation';
import { type BreadcrumbItem } from '@/types';

type TokenAccess = 'read' | 'read_write' | 'write' | 'none';

interface Token {
    id: number;
    name: string;
    access: TokenAccess;
    expires_at: string | null;
    last_used_at: string | null;
    created_at: string;
}

interface Props {
    tokens: Token[];
    newToken: string | null;
    expirationDays: number[];
}

const props = defineProps<Props>();

const page = usePage();
const isViewer = computed(() => page.props.auth.user?.role === 'viewer');

const accessLabels: Record<TokenAccess, string> = {
    read: 'Read only',
    read_write: 'Read and write',
    write: 'Write only',
    none: 'No access',
};

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'API Tokens',
        href: index().url,
    },
];

const displayToken = computed(() => props.newToken);
const copied = ref(false);

function copyToken() {
    if (displayToken.value) {
        navigator.clipboard.writeText(displayToken.value);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    }
}

const tokenToDelete = ref<Token | null>(null);
const deleting = ref(false);

function deleteToken() {
    if (!tokenToDelete.value) {
        return;
    }

    deleting.value = true;
    router.delete(ApiTokenController.destroy.url(tokenToDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            tokenToDelete.value = null;
        },
    });
}

function downloadPostman() {
    window.location.href = documentationPostman().url;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="API Tokens" />

        <h1 class="sr-only">API Tokens</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="API Tokens"
                    description="Create and manage API tokens for external integrations"
                />

                <Alert
                    v-if="displayToken"
                    variant="default"
                    class="bg-green-50 dark:bg-green-950"
                >
                    <AlertTitle class="text-green-800 dark:text-green-200">
                        Token created successfully
                    </AlertTitle>
                    <AlertDescription class="mt-2">
                        <p
                            class="mb-2 text-sm text-green-700 dark:text-green-300"
                        >
                            Make sure to copy your token now. You won't be able
                            to see it again.
                        </p>
                        <div class="flex items-center gap-2">
                            <code
                                class="flex-1 rounded bg-green-100 p-2 font-mono text-xs break-all text-green-800 dark:bg-green-900 dark:text-green-200"
                            >
                                {{ displayToken }}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copyToken"
                            >
                                <Copy class="h-4 w-4" />
                                {{ copied ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                    </AlertDescription>
                </Alert>

                <p v-if="isViewer" class="text-sm text-muted-foreground">
                    Viewers have read-only access and cannot create API tokens.
                    Ask an admin to change your role.
                </p>

                <Form
                    v-else
                    v-bind="ApiTokenController.store.form()"
                    class="space-y-4"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Token name</Label>
                        <Input
                            id="name"
                            name="name"
                            placeholder="e.g. My Integration"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="access">Access</Label>
                        <select
                            id="access"
                            name="access"
                            class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            :disabled="processing"
                            required
                        >
                            <option value="read">Read only</option>
                            <option value="read_write">Read and write</option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            A read-only token can read data through the API and
                            the MCP server. It cannot change anything or send
                            mail.
                        </p>
                        <InputError :message="errors.access" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="expires_in_days">Expiration</Label>
                        <select
                            id="expires_in_days"
                            name="expires_in_days"
                            class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                            :disabled="processing"
                        >
                            <option value="">Never</option>
                            <option
                                v-for="days in expirationDays"
                                :key="days"
                                :value="days"
                            >
                                After {{ days }} days
                            </option>
                        </select>
                        <InputError :message="errors.expires_in_days" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="create-token-button"
                        >
                            Create Token
                        </Button>

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
                                Created.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>

            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Existing Tokens"
                    description="Manage your existing API tokens"
                />

                <div
                    v-if="tokens.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No API tokens created yet.
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="token in tokens"
                        :key="token.id"
                        class="flex items-center justify-between rounded-lg border p-4"
                    >
                        <div>
                            <p class="font-medium">{{ token.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ accessLabels[token.access] }}
                                &middot;
                                <span v-if="token.expires_at">
                                    Expires
                                    <span class="font-mono">{{
                                        token.expires_at
                                    }}</span>
                                </span>
                                <span v-else>Does not expire</span>
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Created {{ token.created_at }}
                                <span v-if="token.last_used_at">
                                    &middot; Last used {{ token.last_used_at }}
                                </span>
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="tokenToDelete = token"
                        >
                            <Trash2 class="h-4 w-4" />
                            Delete
                        </Button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Postman Collection"
                    description="Download a Postman collection to test the API"
                />

                <div>
                    <Button variant="outline" @click="downloadPostman">
                        <Download class="h-4 w-4" />
                        Download Postman Collection
                    </Button>
                    <p class="mt-2 text-sm text-muted-foreground">
                        After importing, set the
                        <code class="rounded bg-muted px-1 py-0.5"
                            >api_token</code
                        >
                        variable to your API token.
                    </p>
                </div>
            </div>
        </SettingsLayout>

        <ConfirmDialog
            :is-open="tokenToDelete !== null"
            :title="`Delete the token ${tokenToDelete?.name ?? ''}?`"
            description="Integrations that use this token stop working at once. You cannot undo this."
            confirm-label="Delete token"
            :processing="deleting"
            @update:is-open="(open) => !open && (tokenToDelete = null)"
            @confirm="deleteToken"
        />
    </AppLayout>
</template>
