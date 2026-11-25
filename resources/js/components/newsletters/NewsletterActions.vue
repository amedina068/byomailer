<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { PhCalendarX, PhPencilSimple } from '@phosphor-icons/vue';
import { ref } from 'vue';

import NewsletterUnscheduleController from '@/actions/App/Http/Controllers/NewsletterUnscheduleController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy as newsletterDestroy } from '@/routes/newsletters';
import { show as newsletterContentShow } from '@/routes/newsletters/content';

const props = defineProps<{
    newsletter: {
        id: number;
        status: string;
    };
}>();

const deleteDialogOpen = ref(false);
const deletingNewsletter = ref(false);
const unschedulingNewsletter = ref(false);

const deleteNewsletter = (): void => {
    if (deletingNewsletter.value) {
        return;
    }

    deletingNewsletter.value = true;

    router.delete(newsletterDestroy(props.newsletter.id).url, {
        onFinish: () => {
            deletingNewsletter.value = false;
            deleteDialogOpen.value = false;
        },
    });
};

const unscheduleNewsletter = (): void => {
    if (unschedulingNewsletter.value) {
        return;
    }

    unschedulingNewsletter.value = true;

    router.delete(NewsletterUnscheduleController.url(props.newsletter.id), {
        preserveScroll: true,
        onFinish: () => {
            unschedulingNewsletter.value = false;
        },
    });
};
</script>

<template>
    <div class="flex items-center gap-2">
        <Button
            v-if="newsletter.status === 'scheduled'"
            type="button"
            variant="outline"
            size="sm"
            :disabled="unschedulingNewsletter"
            @click="unscheduleNewsletter"
        >
            <PhCalendarX :size="14" class="mr-1.5" />
            {{ unschedulingNewsletter ? 'Unscheduling…' : 'Unschedule' }}
        </Button>
        <Button as-child variant="outline" size="sm">
            <Link :href="newsletterContentShow(newsletter.id).url">
                <PhPencilSimple :size="14" class="mr-1.5" />
                Edit
            </Link>
        </Button>
        <Button
            type="button"
            variant="destructive"
            size="sm"
            :disabled="deletingNewsletter"
            @click="deleteDialogOpen = true"
        >
            {{ deletingNewsletter ? 'Deleting…' : 'Delete' }}
        </Button>

        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete newsletter</DialogTitle>
                    <DialogDescription>
                        This newsletter and its content will be permanently
                        removed. This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="flex flex-row justify-end gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="deletingNewsletter"
                        >
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="deletingNewsletter"
                        @click="deleteNewsletter"
                    >
                        {{ deletingNewsletter ? 'Deleting…' : 'Delete newsletter' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
