<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

interface Block {
    id: number;
    uuid: string;
    name: string;
    html_content: string;
    created_at: string;
    updated_at: string;
}

interface Props {
    block?: Block;
}

const props = withDefaults(defineProps<Props>(), {
    block: undefined,
});

const isEditing = !!props.block;

const form = useForm({
    name: props.block?.name ?? '',
    html_content: props.block?.html_content ?? '',
});

const submit = () => {
    if (isEditing) {
        form.put(`/blocks/${props.block!.id}`);
    } else {
        form.post('/blocks');
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <label for="name" class="text-sm font-medium text-foreground">
                Block name
            </label>
            <input
                id="name"
                v-model="form.name"
                type="text"
                placeholder="welcome_header"
                required
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="form.errors.name" />
            <p class="text-xs text-muted-foreground">
                Lowercase letters, numbers, and underscores only. Use this name
                in emails as
                <code v-text="'{{ block.your_name }}'"></code>
            </p>
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-foreground">
                Content
            </label>
            <TiptapEditor
                v-model="form.html_content"
                placeholder="Write your block content..."
                :disabled="form.processing"
            />
            <InputError :message="form.errors.html_content" />
            <p class="text-xs text-muted-foreground">
                You can use contact variables like
                <code v-text="'{{ name }}'"></code> or
                <code v-text="'{{ email }}'"></code> inside blocks.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" class="mr-2" />
                {{ isEditing ? 'Update block' : 'Create block' }}
            </Button>
            <p
                v-if="form.recentlySuccessful"
                class="text-xs text-muted-foreground"
            >
                {{ isEditing ? 'Block updated.' : 'Block created.' }}
            </p>
        </div>
    </form>
</template>
