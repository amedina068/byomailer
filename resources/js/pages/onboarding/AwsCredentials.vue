<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    CheckCircleIcon,
    ExternalLinkIcon,
    Loader2Icon,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

import AwsOnboardingController from '@/actions/App/Http/Controllers/Onboarding/AwsOnboardingController';
import InputError from '@/components/InputError.vue';
import StepIndicator from '@/components/onboarding/StepIndicator.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { iamPolicyJson } from '@/composables/useIamPolicy';
import OnboardingLayout from '@/layouts/onboarding/OnboardingLayout.vue';

defineProps<{
    hasCredentials?: boolean;
}>();

const steps = [
    { number: 1, title: 'IAM User' },
    { number: 2, title: 'Permissions' },
    { number: 3, title: 'Credentials' },
    { number: 4, title: 'Complete' },
];

const currentStep = ref(1);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const copied = ref(false);

// Form data
const accessKey = ref('');
const secretKey = ref('');
const region = ref('us-east-1');

const canProceed = computed(() => {
    switch (currentStep.value) {
        case 3:
            return accessKey.value && secretKey.value && region.value;
        default:
            return true;
    }
});

const goNext = (): void => {
    if (currentStep.value < 4) {
        currentStep.value++;
    }
};

const goBack = (): void => {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

const verifyAndSaveCredentials = async (): Promise<void> => {
    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(
            AwsOnboardingController.storeCredentials.url(),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    access_key: accessKey.value,
                    secret_key: secretKey.value,
                    region: region.value,
                }),
            },
        );

        if (response.status === 419) {
            errors.value.credentials =
                'Your session expired. Reload the page and try again.';
            return;
        }

        const data = await response.json();

        if (response.status === 422 && data.errors) {
            for (const [field, messages] of Object.entries(data.errors)) {
                errors.value[field] = (messages as string[])[0];
            }
            return;
        }

        if (!response.ok) {
            errors.value.credentials =
                data.error || 'Failed to verify credentials';
            return;
        }

        goNext();
    } catch {
        errors.value.credentials =
            'An error occurred while verifying credentials';
    } finally {
        processing.value = false;
    }
};

const goToEmailIdentities = (): void => {
    router.visit('/settings/identities');
};

const getCsrfToken = (): string => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

const copyToClipboard = async (text: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    }

    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
</script>

<template>
    <OnboardingLayout
        title="AWS SES Setup"
        description="Connect your AWS account to send emails through Amazon SES"
    >
        <Head title="AWS SES Setup" />

        <div class="space-y-8">
            <StepIndicator :steps="steps" :current-step="currentStep" />

            <!-- Step 1: Create IAM User -->
            <div v-if="currentStep === 1" class="space-y-6">
                <div class="rounded-lg border bg-card p-6">
                    <h2 class="mb-4 text-lg font-semibold">
                        Create an IAM User in AWS
                    </h2>
                    <ol
                        class="list-inside list-decimal space-y-3 text-sm text-muted-foreground"
                    >
                        <li>Sign in to your AWS Console</li>
                        <li>
                            Navigate to <strong>IAM</strong> (Identity and
                            Access Management)
                        </li>
                        <li>
                            Click <strong>Users</strong> in the sidebar, then
                            <strong>Create user</strong>
                        </li>
                        <li>
                            Enter a username (e.g.,
                            <code class="rounded bg-muted px-1.5 py-0.5"
                                >mail-automation-ses</code
                            >)
                        </li>
                        <li>
                            Click <strong>Next</strong> to proceed to
                            permissions
                        </li>
                    </ol>

                    <div class="mt-4">
                        <Button
                            as="a"
                            href="https://console.aws.amazon.com/iam/home#/users"
                            target="_blank"
                            variant="outline"
                        >
                            Open AWS IAM Console
                            <ExternalLinkIcon class="ml-2 h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button @click="goNext">
                        Next: Set Permissions
                        <ArrowRightIcon class="ml-2 h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Step 2: Set Permissions -->
            <div v-if="currentStep === 2" class="space-y-6">
                <div class="rounded-lg border bg-card p-6">
                    <h2 class="mb-4 text-lg font-semibold">
                        Attach the Required Policy
                    </h2>
                    <ol
                        class="list-inside list-decimal space-y-3 text-sm text-muted-foreground"
                    >
                        <li>
                            On the permissions page, click
                            <strong>Attach policies directly</strong>
                        </li>
                        <li>
                            Click <strong>Create policy</strong> to create a
                            custom policy
                        </li>
                        <li>
                            Select the <strong>JSON</strong> tab and paste the
                            policy below
                        </li>
                        <li>
                            Name the policy (e.g.,
                            <code class="rounded bg-muted px-1.5 py-0.5"
                                >MailAutomationSESPolicy</code
                            >)
                        </li>
                        <li>
                            Attach the policy to your new user —
                            <a
                                href="/documentation/guides/attach-policies"
                                target="_blank"
                                class="inline-flex items-center gap-1 text-primary underline underline-offset-4"
                            >
                                see the step-by-step guide
                                <ExternalLinkIcon class="h-3 w-3" />
                            </a>
                        </li>
                        <li>Complete the user creation wizard</li>
                    </ol>

                    <p class="mt-4 text-sm text-muted-foreground">
                        The SNS permissions allow us to create a topic that
                        receives bounce, complaint, and delivery events from SES
                        automatically.
                    </p>

                    <div class="mt-4">
                        <div class="flex items-center justify-between">
                            <Label>IAM Policy JSON</Label>
                            <a
                                href="/documentation/guides/create-policies"
                                target="_blank"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            >
                                What do these permissions do?
                                <ExternalLinkIcon class="h-3 w-3" />
                            </a>
                        </div>
                        <div class="relative mt-2">
                            <pre
                                class="max-h-64 overflow-auto rounded-lg bg-muted p-4 text-xs"
                            ><code>{{ iamPolicyJson }}</code></pre>
                            <Button
                                variant="outline"
                                size="sm"
                                class="absolute top-2 right-2"
                                @click="copyToClipboard(iamPolicyJson)"
                            >
                                {{ copied ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goBack">
                        <ArrowLeftIcon class="mr-2 h-4 w-4" />
                        Back
                    </Button>
                    <Button @click="goNext">
                        Next: Enter Credentials
                        <ArrowRightIcon class="ml-2 h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Step 3: Enter & Verify Credentials -->
            <div v-if="currentStep === 3" class="space-y-6">
                <div class="rounded-lg border bg-card p-6">
                    <h2 class="mb-4 text-lg font-semibold">
                        Enter Your AWS Credentials
                    </h2>
                    <p class="mb-4 text-sm text-muted-foreground">
                        After creating the user, generate an access key from the
                        user's Security credentials tab.
                    </p>

                    <Alert v-if="hasCredentials" class="mb-4">
                        <AlertTitle>AWS credentials already saved</AlertTitle>
                        <AlertDescription
                            >If you save new keys, they replace your current AWS
                            credentials.</AlertDescription
                        >
                    </Alert>

                    <Alert
                        v-if="errors.credentials"
                        variant="destructive"
                        class="mb-4"
                    >
                        <AlertTitle>Verification Failed</AlertTitle>
                        <AlertDescription>{{
                            errors.credentials
                        }}</AlertDescription>
                    </Alert>

                    <div class="space-y-4">
                        <div class="grid gap-2">
                            <Label for="access_key">Access Key ID</Label>
                            <Input
                                id="access_key"
                                v-model="accessKey"
                                type="text"
                                autocomplete="off"
                                placeholder="AKIA..."
                            />
                            <InputError :message="errors.access_key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="secret_key">Secret Access Key</Label>
                            <Input
                                id="secret_key"
                                v-model="secretKey"
                                type="password"
                                autocomplete="off"
                                placeholder="wJalrXUtnFEMI..."
                            />
                            <InputError :message="errors.secret_key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="region">AWS Region</Label>
                            <Input
                                id="region"
                                v-model="region"
                                type="text"
                                autocomplete="off"
                                placeholder="us-east-1"
                            />
                            <InputError :message="errors.region" />
                            <p class="text-xs text-muted-foreground">
                                Choose a region close to your users. Common
                                options: us-east-1, eu-west-1, ap-southeast-1
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goBack">
                        <ArrowLeftIcon class="mr-2 h-4 w-4" />
                        Back
                    </Button>
                    <Button
                        :disabled="!canProceed || processing"
                        @click="verifyAndSaveCredentials"
                    >
                        <Loader2Icon
                            v-if="processing"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{ processing ? 'Verifying...' : 'Verify & Save' }}
                    </Button>
                </div>
            </div>

            <!-- Step 4: Complete -->
            <div v-if="currentStep === 4" class="space-y-6">
                <div class="rounded-lg border bg-card p-6 text-center">
                    <div
                        class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900"
                    >
                        <CheckCircleIcon
                            class="h-8 w-8 text-green-600 dark:text-green-400"
                        />
                    </div>

                    <h2 class="mb-2 text-xl font-semibold">
                        Credentials Verified!
                    </h2>
                    <p class="mb-6 text-muted-foreground">
                        Your AWS credentials have been verified and saved. You
                        can now set up your email identities to start sending
                        emails.
                    </p>

                    <div class="rounded-lg bg-muted p-4 text-left">
                        <h3 class="mb-2 font-medium">What's next?</h3>
                        <ul
                            class="list-inside list-disc space-y-1 text-sm text-muted-foreground"
                        >
                            <li>Set up your email identity (sender address)</li>
                            <li>Add DNS records to verify your domain</li>
                            <li>
                                Create your first email list and import contacts
                            </li>
                            <li>Send your first newsletter!</li>
                        </ul>
                    </div>
                </div>

                <div class="flex justify-center">
                    <Button size="lg" @click="goToEmailIdentities">
                        Set up an email identity
                        <ArrowRightIcon class="ml-2 h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    </OnboardingLayout>
</template>
