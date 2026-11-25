<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('create-access-keys');
</script>

<template>
    <Head title="Create AWS Access Keys for BYOMailer">
        <meta
            head-key="description"
            name="description"
            content="Generate an AWS access key pair for the BYOMailer IAM user, so BYOMailer can authenticate with Amazon SES. Part of the BYOMailer setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Create Access Keys</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Access keys allow BYOMailer to authenticate with AWS programmatically. You'll generate a key pair for the IAM user you created in the previous step.
            </p>
        </div>

        <section class="mb-12">
            <div class="mb-6 rounded-lg bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-400">
                <strong>Security tip:</strong> Access keys grant programmatic access to your AWS resources. Never share them publicly, commit them to source control, or embed them directly in client-side code.
            </div>

            <h2 class="mb-4 text-2xl font-bold">Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Open Your IAM User</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Sign in to the AWS Console and navigate to IAM. Click on the user you created (e.g. <code class="rounded bg-muted px-1.5 py-0.5">byomailer-ses</code>).
                        </p>
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/iam/home#/users"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open AWS IAM Users
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Go to Security Credentials</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            On the user detail page, click the <strong>Security credentials</strong> tab.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Create Access Key</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Scroll down to the <strong>Access keys</strong> section and click <strong>Create access key</strong>.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Select Use Case</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Select <strong>Third-party service</strong> as the use case. Check the confirmation box acknowledging the recommendation, then click <strong>Next</strong>.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">5</div>
                            <CardTitle class="text-base">Add a Description (Optional)</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            You can add a description tag like <code class="rounded bg-muted px-1.5 py-0.5">BYOMailer SES access</code> to help identify this key later. Click <strong>Create access key</strong>.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">6</div>
                            <CardTitle class="text-base">Copy Your Keys</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            You'll see two values. Copy and save both immediately:
                        </p>
                        <ul class="list-inside list-disc space-y-1 text-sm text-muted-foreground">
                            <li><strong>Access Key ID</strong> &mdash; starts with <code class="rounded bg-muted px-1.5 py-0.5">AKIA...</code></li>
                            <li><strong>Secret Access Key</strong> &mdash; a longer string, only shown once</li>
                        </ul>
                        <div class="rounded-lg bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-400">
                            <strong>Important:</strong> The secret access key is only displayed once. If you lose it, you'll need to create a new access key pair.
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">7</div>
                            <CardTitle class="text-base">Enter Keys in BYOMailer</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            In BYOMailer, go to <strong>Settings &rarr; AWS SES</strong> and paste your Access Key ID and Secret Access Key. BYOMailer encrypts and stores them securely.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">Next Step</h2>
            <p class="text-muted-foreground">
                Now proceed to
                <a href="/documentation/guides/create-policies" class="font-medium text-primary hover:underline">Create IAM Policies</a>
                to define the permissions this user needs.
            </p>
        </section>
    </DocumentationLayout>
</template>
