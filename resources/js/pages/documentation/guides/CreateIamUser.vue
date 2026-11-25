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

const { activeSection, sidebarItems } = useDocumentationSidebar('create-iam-user');
</script>

<template>
    <Head title="Create an IAM User for BYOMailer">
        <meta
            head-key="description"
            name="description"
            content="Create a dedicated AWS IAM user for BYOMailer instead of using your root account credentials. Part of the BYOMailer Amazon SES setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Create an IAM User</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                IAM (Identity and Access Management) lets you create users with specific permissions. You'll create a dedicated user for BYOMailer instead of using your root account credentials.
            </p>
        </div>

        <section class="mb-12">
            <div class="mb-6 rounded-lg bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-400">
                <strong>Why a dedicated IAM user?</strong> Using your root AWS account credentials is a security risk. An IAM user with limited permissions ensures BYOMailer can only access the services it needs (SES and SNS) and nothing else.
            </div>

            <h2 class="mb-4 text-2xl font-bold">Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Open the IAM Console</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Sign in to your AWS Console and navigate to the IAM service.
                        </p>
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/iam/home#/users"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open AWS IAM Console
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Click "Create User"</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            In the IAM Users section, click the <strong>Create user</strong> button.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Enter a Username</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Choose a descriptive username, for example:
                            <code class="rounded bg-muted px-1.5 py-0.5">byomailer-ses</code>. Do <strong>not</strong> enable console access &mdash; this user only needs programmatic access.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Skip Permissions (For Now)</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click <strong>Next</strong> through the permissions step. You'll attach a custom policy in the next guide.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">5</div>
                            <CardTitle class="text-base">Create the User</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Review the details and click <strong>Create user</strong>.
                        </p>
                    </CardContent>
                </Card>

            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">Next Step</h2>
            <p class="text-muted-foreground">
                Now proceed to
                <a href="/documentation/guides/create-access-keys" class="font-medium text-primary hover:underline">Create Access Keys</a>
                to generate the credentials BYOMailer needs.
            </p>
        </section>
    </DocumentationLayout>
</template>
