<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    PhArrowSquareOut,
    PhCheck,
    PhCopy,
    PhDownloadSimple,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import ConnectAwsRoleController from '@/actions/App/Http/Controllers/Onboarding/ConnectAwsRoleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface RoleConnection {
    externalId: string;
    principalArn: string;
    roleName: string;
    templateUrl: string;
    quickCreateUrl: string | null;
}

const props = defineProps<{
    roleConnection: RoleConnection;
    readOnly?: boolean;
}>();

const form = useForm({ aws_account_id: '', region: 'us-east-1' });
const copied = ref<string | null>(null);

const stackValues = computed(() => [
    { label: 'TrustedPrincipalArn', value: props.roleConnection.principalArn },
    { label: 'ExternalId', value: props.roleConnection.externalId },
    { label: 'RoleName', value: props.roleConnection.roleName },
]);

const copy = async (label: string, value: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = label;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        copied.value = null;
    }
};

const submit = (): void => {
    form.post(ConnectAwsRoleController.url(), { preserveScroll: true });
};
</script>

<template>
    <div class="space-y-6 rounded-[6px] border bg-card p-6">
        <div>
            <h2 class="text-lg font-semibold">Connect with an IAM role</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                BYOMailer keeps no AWS keys. A CloudFormation stack makes a role
                in your AWS account. Only BYOMailer can assume it, and only with
                the external ID of your account.
            </p>
        </div>

        <section class="space-y-3">
            <h3 class="text-sm font-medium">1. Make the stack</h3>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="roleConnection.quickCreateUrl"
                    as="a"
                    :href="roleConnection.quickCreateUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Open AWS CloudFormation
                    <PhArrowSquareOut class="ml-2 h-4 w-4" />
                </Button>
                <Button
                    as="a"
                    :href="roleConnection.templateUrl"
                    variant="outline"
                    download
                >
                    Download the template
                    <PhDownloadSimple class="ml-2 h-4 w-4" />
                </Button>
            </div>
            <p
                v-if="!roleConnection.quickCreateUrl"
                class="text-sm text-muted-foreground"
            >
                In the CloudFormation console, choose Create stack, then Upload
                a template file. Enter the values below.
            </p>
            <dl class="divide-y rounded-[6px] border text-sm">
                <div
                    v-for="item in stackValues"
                    :key="item.label"
                    class="flex items-center gap-3 px-3 py-2"
                >
                    <dt class="w-44 shrink-0 font-medium">{{ item.label }}</dt>
                    <dd class="min-w-0 flex-1 truncate font-mono text-xs">
                        {{ item.value }}
                    </dd>
                    <Button
                        variant="ghost"
                        size="sm"
                        :aria-label="`Copy ${item.label}`"
                        @click="copy(item.label, item.value)"
                    >
                        <PhCheck v-if="copied === item.label" class="h-4 w-4" />
                        <PhCopy v-else class="h-4 w-4" />
                    </Button>
                </div>
            </dl>
        </section>

        <form class="space-y-3" @submit.prevent="submit">
            <h3 class="text-sm font-medium">
                2. When the stack is complete, connect it
            </h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="aws_account_id">AWS account ID</Label>
                    <Input
                        id="aws_account_id"
                        v-model="form.aws_account_id"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="123456789012"
                        class="font-mono"
                    />
                    <InputError :message="form.errors.aws_account_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="role_region">SES region</Label>
                    <Input
                        id="role_region"
                        v-model="form.region"
                        autocomplete="off"
                        placeholder="us-east-1"
                    />
                    <InputError :message="form.errors.region" />
                </div>
            </div>
            <Button type="submit" :disabled="form.processing || readOnly">
                {{ form.processing ? 'Connecting...' : 'Connect' }}
            </Button>
        </form>
    </div>
</template>
