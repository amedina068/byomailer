<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Mail, BookOpen, Code, ArrowRight } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home, login, dashboard } from '@/routes';

const guides = [
    {
        title: 'Getting Started with BYOMailer',
        description: 'Everything you need to know to start sending emails with BYOMailer and AWS SES.',
        href: '/documentation/guides/getting-started',
    },
    {
        title: 'Create an AWS Account',
        description: 'Step-by-step instructions for creating your AWS account.',
        href: '/documentation/guides/create-aws-account',
    },
    {
        title: 'Create an IAM User',
        description: 'Learn how to create a dedicated IAM user for BYOMailer.',
        href: '/documentation/guides/create-iam-user',
    },
    {
        title: 'Create IAM Policies',
        description: 'Set up the required permissions policy with the exact SES and SNS permissions BYOMailer needs.',
        href: '/documentation/guides/create-policies',
    },
    {
        title: 'Attach Policies to IAM User',
        description: 'Attach your custom policy to the IAM user so BYOMailer can access AWS services.',
        href: '/documentation/guides/attach-policies',
    },
    {
        title: 'Request Production Access',
        description: 'Move your SES account out of sandbox mode to send emails to any address.',
        href: '/documentation/guides/request-production-access',
    },
];
</script>

<template>
    <Head title="Documentation" />

    <div class="min-h-screen bg-background">
        <!-- Header -->
        <header class="sticky top-0 z-50 border-b bg-background/95 backdrop-blur">
            <div class="container mx-auto flex items-center justify-between px-4 py-4">
                <div class="flex items-center gap-2">
                    <Link :href="home()" class="flex items-center gap-2">
                        <Mail class="h-6 w-6 text-primary" />
                        <span class="text-xl font-semibold">BYOMailer</span>
                    </Link>
                </div>
                <nav class="flex items-center gap-4">
                    <Link v-if="$page.props.auth.user" :href="dashboard()">
                        <Button variant="outline">Dashboard</Button>
                    </Link>
                    <Link v-else :href="login()">
                        <Button variant="outline">Log in</Button>
                    </Link>
                </nav>
            </div>
        </header>

        <div class="container mx-auto px-4 py-12">
            <!-- Hero -->
            <div class="mb-16 text-center">
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">Documentation</h1>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                    Everything you need to set up AWS SES and integrate with the BYOMailer API.
                </p>
            </div>

            <!-- Two Sections -->
            <div class="mx-auto grid max-w-4xl gap-8 md:grid-cols-2">
                <!-- Guides -->
                <Card class="flex flex-col">
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                <BookOpen class="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <CardTitle>Guides</CardTitle>
                                <CardDescription>Step-by-step setup instructions</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="flex grow flex-col">
                        <ul class="grow space-y-3">
                            <li v-for="guide in guides" :key="guide.title">
                                <Link
                                    :href="guide.href"
                                    class="group flex items-start gap-2 text-sm"
                                >
                                    <ArrowRight class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                    <div>
                                        <span class="font-medium text-foreground group-hover:text-primary">{{ guide.title }}</span>
                                        <p class="text-muted-foreground">{{ guide.description }}</p>
                                    </div>
                                </Link>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- REST API -->
                <Card class="flex flex-col">
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                <Code class="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <CardTitle>REST API</CardTitle>
                                <CardDescription>API reference and endpoints</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="flex grow flex-col">
                        <p class="mb-4 grow text-sm text-muted-foreground">
                            Full API reference for managing contacts, tags, and mailing lists programmatically. Includes authentication details, request/response examples, and a downloadable Postman collection.
                        </p>
                        <Link href="/documentation/api">
                            <Button class="w-full">
                                View API Reference
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t py-8">
            <div class="container mx-auto flex flex-col items-center justify-between gap-4 px-4 text-sm text-muted-foreground sm:flex-row">
                <div class="flex items-center gap-2">
                    <Mail class="h-4 w-4" />
                    <span>BYOMailer</span>
                </div>
                <p>&copy; {{ new Date().getFullYear() }} All rights reserved.</p>
            </div>
        </footer>
    </div>
</template>
