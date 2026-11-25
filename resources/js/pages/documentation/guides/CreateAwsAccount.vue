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

const { activeSection, sidebarItems } = useDocumentationSidebar('create-aws-account');
</script>

<template>
    <Head title="Create an AWS Account for Amazon SES">
        <meta
            head-key="description"
            name="description"
            content="Create the Amazon Web Services account that gives you Amazon SES, the email service that BYOMailer sends through. The first step of the BYOMailer setup guide."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Create an AWS Account</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Amazon Web Services (AWS) provides the Simple Email Service (SES) that BYOMailer uses to send your emails. Follow these steps to create your AWS account.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Go to the AWS Sign-Up Page</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Visit the AWS sign-up page and click <strong>Create an AWS Account</strong>.
                        </p>
                        <Button
                            as="a"
                            href="https://portal.aws.amazon.com/billing/signup"
                            target="_blank"
                            variant="outline"
                            size="sm"
                        >
                            Open AWS Sign-Up
                            <ExternalLink class="ml-2 h-4 w-4" />
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Enter Your Email and Account Name</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Provide your root email address and choose an account name. This email will be used for account recovery, so use one you check regularly.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Verify Your Email</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            AWS will send a verification code to your email. Enter the code to proceed.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Add Payment Information</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Enter a credit or debit card. AWS uses a pay-as-you-go model. SES costs <strong>$0.10 per 1,000 emails</strong> on à la carte pricing. New SES accounts start on the Essentials plan at $0.16 per 1,000 emails, and you can switch to à la carte at any time.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">5</div>
                            <CardTitle class="text-base">Choose a Support Plan</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Select the <strong>Basic Support - Free</strong> plan. This is sufficient for SES usage with BYOMailer.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">6</div>
                            <CardTitle class="text-base">Complete Sign-Up</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Once your account is created, sign in to the AWS Management Console. Your account may take a few minutes to activate.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">Next Step</h2>
            <p class="text-muted-foreground">
                Once your AWS account is ready, proceed to
                <a href="/documentation/guides/create-iam-user" class="font-medium text-primary hover:underline">Create an IAM User</a>.
            </p>
        </section>
    </DocumentationLayout>
</template>
