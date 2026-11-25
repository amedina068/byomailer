<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, AlertTriangle } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('request-production-access');
</script>

<template>
    <Head title="Request Amazon SES Production Access">
        <meta
            head-key="description"
            name="description"
            content="Move your Amazon SES account out of sandbox mode, so you can send email to any recipient and not only to verified addresses. Part of the BYOMailer setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Request Production Access</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                New AWS SES accounts start in sandbox mode, which limits you to sending only to verified email addresses. To send to any recipient, you need to request production access.
            </p>
        </div>

        <section class="mb-12">
            <div class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm">
                <div class="flex items-start gap-2 text-amber-700 dark:text-amber-400">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <strong>Sandbox Limitations:</strong> In sandbox mode you can only send emails to verified email addresses and domains, with a maximum of 200 emails per 24-hour period and 1 email per second. Production access removes these restrictions.
                    </div>
                </div>
            </div>

            <h2 class="mb-4 text-2xl font-bold">Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Open the SES Console</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Navigate to the Amazon SES console and select the region you plan to use.
                        </p>
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/ses/home"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open SES Console
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Request Production Access</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            In the SES dashboard, you'll see a banner indicating you're in sandbox mode. Click <strong>Request production access</strong>, or navigate to <strong>Account dashboard</strong> and click the request button there.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Fill Out the Request Form</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">Complete the form with the following details:</p>
                        <ul class="list-inside list-disc space-y-2 text-sm text-muted-foreground">
                            <li><strong>Mail type:</strong> Select "Transactional" or "Marketing" depending on your use case (or both if applicable).</li>
                            <li><strong>Website URL:</strong> Your website or business URL.</li>
                            <li><strong>Use case description:</strong> Explain how you'll use SES. Mention that recipients opt in, you handle bounces/complaints, and include an unsubscribe mechanism.</li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Submit and Wait</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Submit your request. AWS Support gives an initial response within 96 hours. It can take longer if AWS needs more information from you. You'll receive an email notification when your account has been moved to production.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Tips for Approval</h2>
            <div class="space-y-2">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Be specific about your use case.</strong> Mention that you use double opt-in, process bounces and complaints automatically, and include one-click unsubscribe links in every email.
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Mention your sending volume.</strong> Give a realistic estimate of how many emails you plan to send per day or month.
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Explain how contacts are collected.</strong> AWS wants to know recipients have opted in to receive your emails.
                    </p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">What's Next</h2>
            <p class="text-muted-foreground">
                While waiting for production access, you can still set up BYOMailer with sandbox mode (just send to verified addresses). Once approved, go to your BYOMailer dashboard and enter your AWS credentials in the
                <strong>AWS Setup</strong> onboarding to connect your account.
            </p>
        </section>
    </DocumentationLayout>
</template>
