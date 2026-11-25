<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Copy, Check, ExternalLink } from 'lucide-vue-next';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import { iamPermissions, iamPolicyJson } from '@/composables/useIamPolicy';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('create-policies');

const copied = ref(false);

const copyToClipboard = async () => {
    await navigator.clipboard.writeText(iamPolicyJson);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const permissions = iamPermissions;
</script>

<template>
    <Head title="Create the IAM Policy for Amazon SES and SNS">
        <meta
            head-key="description"
            name="description"
            content="Create a custom AWS IAM policy with the exact Amazon SES and SNS permissions that BYOMailer needs, with the policy JSON to copy. Part of the BYOMailer setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Create IAM Policies</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                IAM policies define what actions a user can perform. You'll create a custom policy with the exact permissions BYOMailer needs to operate.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Create the Policy</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Open the IAM Policies Page</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            In the AWS Console, navigate to <strong>IAM &rarr; Policies</strong> and click <strong>Create policy</strong>.
                        </p>
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/iam/home#/policies"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open IAM Policies
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Switch to JSON Editor</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click the <strong>JSON</strong> tab in the policy editor, clear the default content, and paste the policy below.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Paste the Policy JSON</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="relative">
                            <pre class="max-h-80 overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100"><code>{{ iamPolicyJson }}</code></pre>
                            <Button
                                variant="outline"
                                size="sm"
                                class="absolute right-2 top-2"
                                @click="copyToClipboard"
                            >
                                <Check v-if="copied" class="mr-1 h-3 w-3" />
                                <Copy v-else class="mr-1 h-3 w-3" />
                                {{ copied ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Name and Save the Policy</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click <strong>Next</strong>, then name the policy (e.g., <code class="rounded bg-muted px-1.5 py-0.5">BYOMailerSESPolicy</code>). Optionally add a description, then click <strong>Create policy</strong>.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Permission Breakdown</h2>
            <p class="mb-6 text-muted-foreground">
                Here's what each permission does and why BYOMailer needs it.
            </p>

            <div class="space-y-8">
                <div v-for="group in permissions" :key="group.category">
                    <h3 class="mb-3 text-lg font-semibold">{{ group.category }}</h3>
                    <div class="space-y-2">
                        <div
                            v-for="item in group.items"
                            :key="item.action"
                            class="rounded-lg border p-4"
                        >
                            <code class="text-sm font-medium text-primary">{{ item.action }}</code>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">Next Step</h2>
            <p class="text-muted-foreground">
                Now proceed to
                <a href="/documentation/guides/attach-policies" class="font-medium text-primary hover:underline">Attach Policies to Your IAM User</a>.
            </p>
        </section>
    </DocumentationLayout>
</template>
