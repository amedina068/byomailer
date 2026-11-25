<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import EmailIdentityController from '@/actions/App/Http/Controllers/Settings/EmailIdentityController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { edit as listEdit } from '@/routes/lists';
import { show as newsletterShow } from '@/routes/newsletters';
import { show as sequenceShow } from '@/routes/sequences';

export interface UsageItem {
    id: number;
    name: string;
}

export interface IdentityUsage {
    newsletters: UsageItem[];
    sequences: UsageItem[];
    lists: UsageItem[];
}

const props = defineProps<{
    identityId: number;
    identityEmail: string;
    usage: IdentityUsage;
}>();

const open = defineModel<boolean>('open', { required: true });
const deleting = ref(false);
const serverError = ref('');

const usageGroups = computed(() =>
    [
        {
            label: 'Scheduled or sending newsletters',
            items: props.usage.newsletters,
            href: (id: number) => newsletterShow.url(id),
        },
        {
            label: 'Sequences with live steps',
            items: props.usage.sequences,
            href: (id: number) => sequenceShow.url(id),
        },
        {
            label: 'Lists that send an opt-in email',
            items: props.usage.lists,
            href: (id: number) => listEdit.url(id),
        },
    ].filter((group) => group.items.length > 0),
);

const isInUse = computed(() => usageGroups.value.length > 0);

const confirmDelete = (): void => {
    deleting.value = true;
    serverError.value = '';
    router.delete(
        EmailIdentityController.destroy.url({
            emailIdentity: props.identityId,
        }),
        {
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                serverError.value =
                    errors.identity ?? 'Unable to remove the email identity.';
            },
            onFinish: () => {
                deleting.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove email identity?</DialogTitle>
                <DialogDescription v-if="isInUse">
                    These items send from
                    <span class="font-mono break-all">{{ identityEmail }}</span
                    >. Change their sender or stop them before you remove this
                    email identity.
                </DialogDescription>
                <DialogDescription v-else>
                    This removes
                    <span class="font-mono break-all">{{ identityEmail }}</span>
                    from BYOMailer. Draft newsletters and draft sequence steps
                    that use it lose their sender. The email identity stays
                    verified in your AWS account.
                </DialogDescription>
            </DialogHeader>

            <div v-if="isInUse" class="space-y-3 text-sm">
                <div v-for="group in usageGroups" :key="group.label">
                    <p class="font-medium" style="color: var(--bm-text-1)">
                        {{ group.label }}
                        <span class="font-mono"
                            >({{ group.items.length }})</span
                        >
                    </p>
                    <ul class="mt-1 space-y-1">
                        <li v-for="item in group.items" :key="item.id">
                            <Link
                                :href="group.href(item.id)"
                                class="break-all underline underline-offset-2"
                                style="color: var(--bm-accent)"
                            >
                                {{ item.name }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <p
                v-if="serverError"
                role="alert"
                class="text-sm"
                style="color: var(--bm-error)"
            >
                {{ serverError }}
            </p>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="deleting"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="deleting || isInUse"
                    @click="confirmDelete"
                >
                    {{ deleting ? 'Removing...' : 'Remove' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
