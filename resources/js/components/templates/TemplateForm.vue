<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import EmailTemplateController from '@/actions/App/Http/Controllers/EmailTemplateController';
import InputError from '@/components/InputError.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { countContentPlaceholders } from '@/lib/template-slot';

interface EmailTemplate {
    id: number;
    name: string;
    html_content: string;
    created_at: string;
    updated_at: string;
}

interface Props {
    template?: EmailTemplate;
}

const props = withDefaults(defineProps<Props>(), {
    template: undefined,
});

const isEditing = !!props.template;

const form = useForm({
    name: props.template?.name ?? '',
    html_content: props.template?.html_content ?? '',
    confirmed_no_unsubscribe: false as boolean,
});

const isUnsubWarningOpen = ref(false);
const localError = ref<string | null>(null);

const placeholderCount = computed(() => countContentPlaceholders(form.html_content));

const hasUnsubAnchor = (html: string): boolean => {
    return /href\s*=\s*["']\s*\{\{\s*unsubscribe_url\s*\}\}\s*["']/.test(html);
};

const actuallySubmit = () => {
    if (isEditing) {
        form.put(EmailTemplateController.update(props.template!.id).url);
    } else {
        form.post(EmailTemplateController.store().url);
    }
};

const submit = () => {
    localError.value = null;

    if (placeholderCount.value === 0) {
        localError.value = 'Add a {{ content }} placeholder so newsletters and sequence steps know where to drop their content.';

        return;
    }

    if (placeholderCount.value > 1) {
        localError.value = 'Only one {{ content }} placeholder is allowed per template.';

        return;
    }

    if (!hasUnsubAnchor(form.html_content) && !form.confirmed_no_unsubscribe) {
        isUnsubWarningOpen.value = true;

        return;
    }

    actuallySubmit();
};

const confirmUnsubWaiver = () => {
    form.confirmed_no_unsubscribe = true;
    isUnsubWarningOpen.value = false;
    actuallySubmit();
};

const ph = {
    content: '{{ content }}',
    name: '{{ name }}',
    unsubscribeUrl: '{{ unsubscribe_url }}',
    preferencesUrl: '{{ preferences_url }}',
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <label for="name" class="text-sm font-medium text-foreground">
                Template name
            </label>
            <input
                id="name"
                v-model="form.name"
                type="text"
                placeholder="e.g. Welcome Email"
                required
                class="rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-foreground">
                Template content
            </label>
            <TiptapEditor
                v-model="form.html_content"
                mode="template"
                placeholder="Write your template. Use the content placeholder button to mark the editable slot."
                :disabled="form.processing"
            />
            <InputError :message="form.errors.html_content" />
            <p v-if="localError" class="text-xs text-destructive">
                {{ localError }}
            </p>
            <p class="text-xs text-muted-foreground">
                Use
                <code class="rounded bg-muted px-1 font-mono">{{ ph.content }}</code>
                exactly once to mark the editable slot. You can also use
                <code class="rounded bg-muted px-1 font-mono">{{ ph.name }}</code>,
                <code class="rounded bg-muted px-1 font-mono">{{ ph.unsubscribeUrl }}</code>,
                and
                <code class="rounded bg-muted px-1 font-mono">{{ ph.preferencesUrl }}</code>
                anywhere in the template.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" class="mr-2" />
                {{ isEditing ? 'Update template' : 'Create template' }}
            </Button>
            <p
                v-if="form.recentlySuccessful"
                class="text-xs text-muted-foreground"
            >
                {{ isEditing ? 'Template updated.' : 'Template created.' }}
            </p>
        </div>

        <Dialog :open="isUnsubWarningOpen" @update:open="isUnsubWarningOpen = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>No unsubscribe link detected</DialogTitle>
                    <DialogDescription>
                        We couldn't find an unsubscribe link using
                        <code class="font-mono">{{ ph.unsubscribeUrl }}</code>
                        in your template. Sending bulk email without a working
                        unsubscribe link can hurt your sender reputation and
                        violate anti-spam laws. We strongly recommend keeping
                        one.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="isUnsubWarningOpen = false"
                    >
                        Back to editor
                    </Button>
                    <Button
                        variant="destructive"
                        @click="confirmUnsubWaiver"
                    >
                        Save without unsubscribe link
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </form>
</template>
