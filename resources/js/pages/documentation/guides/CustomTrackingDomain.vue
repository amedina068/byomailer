<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle, Globe, ShieldCheck, TrendingUp } from 'lucide-vue-next';

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('custom-tracking-domain');
</script>

<template>
    <Head title="Custom Tracking Domain for Amazon SES">
        <meta
            head-key="description"
            name="description"
            content="Replace the default awstrack.me open and click tracking links with your own HTTPS subdomain to improve deliverability and build trust with your recipients."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Custom Tracking Domain</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Replace the default <code class="text-sm">awstrack.me</code> tracking links with your own branded subdomain served over HTTPS to improve deliverability and build trust with your recipients.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Why Use a Custom Tracking Domain?</h2>
            <p class="mb-6 text-muted-foreground">
                When you enable open or click tracking in SES, every tracked link in your emails is rewritten to pass through a tracking domain. By default this is <code class="text-sm">awstrack.me</code>, a shared domain used by every SES sender. A custom tracking domain gives you several advantages:
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <TrendingUp class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                            <CardTitle class="text-base">Better Deliverability</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Shared tracking domains can accumulate negative reputation from other senders. Your own subdomain isolates your reputation and prevents other senders from affecting your inbox placement.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <Globe class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                            <CardTitle class="text-base">Brand Consistency</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Recipients see your domain in tracking links instead of a generic AWS domain. This builds trust and reduces the chance of your emails being flagged as suspicious.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <ShieldCheck class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                            <CardTitle class="text-base">Harder to Filter</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Some inbox providers and corporate firewalls flag or strip links from well-known shared tracking domains. A custom subdomain avoids these blanket filters.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <CheckCircle class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                            <CardTitle class="text-base">DMARC Alignment</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Using a subdomain of your sending domain helps maintain DMARC alignment for your tracking links, which further improves authentication and deliverability.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Setup Steps</h2>
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">1</div>
                            <CardTitle class="text-base">Choose a Subdomain</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Pick a subdomain of your sending domain. We recommend something that blends naturally with your brand, such as <code class="text-xs">byot.yourdomain.com</code>. Avoid using obvious names like <code class="text-xs">track</code> or <code class="text-xs">click</code> since some filters specifically look for these.
                        </p>
                        <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                            <strong>Important:</strong> Always use a subdomain, not your root domain. Pointing the root A record at our proxy would break every other service on your domain.
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">2</div>
                            <CardTitle class="text-base">Enable Custom Tracking in BYOMailer</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Go to <strong>Settings &rarr; Email Credentials</strong> and scroll to the <strong>Custom Tracking Domain</strong> section. Check the box, enter your chosen subdomain, and click <strong>Save Tracking Domain</strong>.
                        </p>
                        <p class="text-sm text-muted-foreground">
                            BYOMailer will show you the exact IP address to use as the A record value. Tracking links are then served over HTTPS through our managed proxy, which holds a valid Let's Encrypt certificate for your subdomain.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">3</div>
                            <CardTitle class="text-base">Add the A Record</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            In your DNS provider (Cloudflare, Route 53, Namecheap, etc.), create an A record pointing your subdomain at the IP shown on your Email Credentials page:
                        </p>
                        <div class="overflow-hidden rounded-lg border">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/50">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">Type</th>
                                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">Name</th>
                                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="px-4 py-2 font-mono">A</td>
                                        <td class="px-4 py-2 font-mono">byot</td>
                                        <td class="px-4 py-2 font-mono">&lt;ip from settings&gt;</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            If you use Cloudflare, make sure the proxy is <strong>disabled</strong> (grey cloud / DNS only) for this record so our proxy can issue and serve the TLS certificate directly.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">4</div>
                            <CardTitle class="text-base">Verify the Domain</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Back on the Email Credentials page, click <strong>Check Status</strong>. BYOMailer will verify that your A record points to our proxy and automatically activate the custom tracking domain on SES.
                        </p>
                        <p class="text-sm text-muted-foreground">
                            DNS changes can take a few minutes to propagate. If verification doesn't succeed immediately, wait a few minutes and try again. BYOMailer also checks automatically every 30 minutes in the background.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Tips</h2>
            <div class="space-y-2">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Use a non-obvious subdomain name.</strong> Names like <code class="text-xs">byot</code>, <code class="text-xs">go</code>, or <code class="text-xs">r</code> are less likely to be flagged by content filters than <code class="text-xs">track</code> or <code class="text-xs">click</code>.
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Match your sending domain.</strong> If you send from <code class="text-xs">newsletter@example.com</code>, use <code class="text-xs">byot.example.com</code> as the tracking domain. This keeps everything under the same root domain for better DMARC alignment.
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">Disable Cloudflare proxy.</strong> If your DNS is managed by Cloudflare, the A record must use "DNS only" mode (grey cloud). Cloudflare's proxy will block our certificate issuance and break tracking.
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">
                        <strong class="text-foreground">You can change domains later.</strong> If you need to switch to a different subdomain, save the new domain in settings. BYOMailer will clean up the old one on SES and guide you through setting up the new A record.
                    </p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-2xl font-bold">What's Next</h2>
            <p class="text-muted-foreground">
                Once your custom tracking domain is verified, all future tracked emails will use your branded subdomain automatically. No changes to your newsletters or sequences are needed. Head to
                <Link href="/documentation/guides/getting-started" class="font-medium text-primary underline underline-offset-4 hover:text-primary/80">Getting Started</Link>
                if you still need to configure other parts of your BYOMailer setup.
            </p>
        </section>
    </DocumentationLayout>
</template>
