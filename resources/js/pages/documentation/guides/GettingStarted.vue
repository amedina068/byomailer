<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } =
    useDocumentationSidebar('getting-started');

const steps = [
    {
        number: 1,
        title: 'Create an AWS Account',
        description:
            "Sign up for Amazon Web Services if you don't already have an account.",
        href: '/documentation/guides/create-aws-account',
    },
    {
        number: 2,
        title: 'Create an IAM User',
        description:
            'Create a dedicated IAM user for BYOMailer to use with your AWS account.',
        href: '/documentation/guides/create-iam-user',
    },
    {
        number: 3,
        title: 'Create Access Keys',
        description:
            'Generate access key credentials for the IAM user so BYOMailer can authenticate with AWS.',
        href: '/documentation/guides/create-access-keys',
    },
    {
        number: 4,
        title: 'Create IAM Policies',
        description:
            'Define the exact permissions BYOMailer needs to send emails and manage SES.',
        href: '/documentation/guides/create-policies',
    },
    {
        number: 5,
        title: 'Attach Policies to Your IAM User',
        description: 'Connect the permissions policy to your IAM user.',
        href: '/documentation/guides/attach-policies',
    },
    {
        number: 6,
        title: 'Request Production Access',
        description:
            'Move out of the SES sandbox to send emails to any address.',
        href: '/documentation/guides/request-production-access',
    },
];
</script>

<template>
    <Head title="Getting Started: Email Marketing on Your Own AWS SES">
        <meta
            head-key="description"
            name="description"
            content="Set up BYOMailer to send email marketing through your own Amazon SES account: create the AWS account, IAM user, access keys, and policy, then request production access."
        />
    </Head>

    <DocumentationLayout
        :active-section="activeSection"
        :sidebar-items="sidebarItems"
    >
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">
                Getting Started with BYOMailer
            </h1>
            <p class="mt-4 text-lg text-muted-foreground">
                BYOMailer lets you send email marketing campaigns through your
                own AWS SES account. This guide walks you through the complete
                setup process.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-2 text-2xl font-bold">How It Works</h2>
            <p class="text-muted-foreground">
                Instead of paying per-subscriber fees to traditional email
                platforms, BYOMailer connects directly to Amazon SES, where you
                pay only for emails sent (from $0.10 per 1,000 emails on SES à
                la carte pricing). You bring your own AWS account, and BYOMailer
                handles the rest: contact management, list segmentation,
                newsletters, automations, and more.
            </p>
        </section>

        <section class="mb-12">
            <h2 class="mb-6 text-2xl font-bold">Setup Steps</h2>
            <div class="space-y-4">
                <Card v-for="step in steps" :key="step.number">
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                            >
                                {{ step.number }}
                            </div>
                            <CardTitle class="text-base">{{
                                step.title
                            }}</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="mb-3 text-sm text-muted-foreground">
                            {{ step.description }}
                        </p>
                        <Link
                            :href="step.href"
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                        >
                            Read guide
                            <ArrowRight class="h-3 w-3" />
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">What You'll Need</h2>
            <ul class="list-inside list-disc space-y-2 text-muted-foreground">
                <li>An email address to create your AWS account</li>
                <li>
                    A credit or debit card for AWS billing (SES costs are
                    minimal)
                </li>
                <li>
                    A domain you own for sending emails (e.g., yourdomain.com)
                </li>
                <li>About 15-20 minutes to complete the full setup</li>
            </ul>
        </section>
    </DocumentationLayout>
</template>
