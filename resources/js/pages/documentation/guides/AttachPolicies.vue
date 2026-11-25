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

const { activeSection, sidebarItems } = useDocumentationSidebar('attach-policies');
</script>

<template>
    <Head title="Attach the BYOMailer Policy to Your IAM User">
        <meta
            head-key="description"
            name="description"
            content="Attach the custom BYOMailer IAM policy to your IAM user, so it gets the Amazon SES and SNS permissions that BYOMailer needs. Part of the BYOMailer setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Attach Policies to IAM User</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Now that you've created the BYOMailer policy, attach it to your IAM user so it has the required permissions.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Open the IAM Users Page</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Navigate to <strong>IAM &rarr; Users</strong> in the AWS Console and click on your BYOMailer user (e.g., <code class="rounded bg-muted px-1.5 py-0.5">byomailer-ses</code>).
                        </p>
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/iam/home#/users"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open IAM Users
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Go to the Permissions Tab</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click the <strong>Permissions</strong> tab on the user detail page.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Add Permissions</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click <strong>Add permissions</strong>, then select <strong>Attach policies directly</strong>.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Search and Select Your Policy</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Search for the policy you created earlier (e.g., <code class="rounded bg-muted px-1.5 py-0.5">BYOMailerSESPolicy</code>). Check the box next to it.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">5</div>
                            <CardTitle class="text-base">Confirm</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Click <strong>Add permissions</strong> to attach the policy. You should now see the policy listed under the user's permissions.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-2 text-2xl font-bold">Verify It Worked</h2>
            <p class="text-muted-foreground">
                After attaching the policy, the user's Permissions tab should show <code class="rounded bg-muted px-1.5 py-0.5">BYOMailerSESPolicy</code> listed under attached policies. The policy should show permissions for <strong>SES</strong>, <strong>SNS</strong>, and <strong>STS</strong> services.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">Next Step</h2>
            <p class="text-muted-foreground">
                Proceed to
                <a href="/documentation/guides/request-production-access" class="font-medium text-primary hover:underline">Request Production Access</a>
                to move your SES account out of sandbox mode.
            </p>
        </section>
    </DocumentationLayout>
</template>
