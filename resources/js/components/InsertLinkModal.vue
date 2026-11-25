<script setup lang="ts">
import type { Editor } from '@tiptap/core';
import { computed, ref, toRaw, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LINK_NAME_MAX_LENGTH, normalizeLinkName } from '@/lib/linkClicks';

const props = defineProps<{
    isOpen: boolean;
    editor?: Editor;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
}>();

const linkUrl = ref('');
const linkName = ref('');

const normalizedName = computed(() => normalizeLinkName(linkName.value));

/** A reactive proxy of the editor breaks its transactions. */
const editor = (): Editor | undefined => toRaw(props.editor);

watch(
    () => props.isOpen,
    (open) => {
        if (open) {
            const attributes = editor()?.getAttributes('link') ?? {};
            linkUrl.value = attributes.href ?? '';
            linkName.value = attributes.linkName ?? '';
        }
    },
);

const submit = (): void => {
    const url = linkUrl.value.trim();

    if (!url) {
        return;
    }

    editor()
        ?.chain()
        .focus()
        .extendMarkRange('link')
        .setLink({ href: url })
        .updateAttributes('link', { linkName: normalizedName.value })
        .run();
    emit('update:isOpen', false);
};
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Insert link</DialogTitle>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid gap-1.5">
                    <Label for="link-url">URL</Label>
                    <input
                        id="link-url"
                        v-model="linkUrl"
                        type="url"
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        placeholder="https://example.com"
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label for="link-name">
                        Link name
                        <span class="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <input
                        id="link-name"
                        v-model="linkName"
                        type="text"
                        :maxlength="LINK_NAME_MAX_LENGTH"
                        class="rounded-lg border border-border bg-background px-3 py-2 font-(family-name:--bm-font-mono) text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        placeholder="black-friday-cta"
                    />
                    <p class="text-xs text-muted-foreground">
                        Name a link to track its clicks in reports and
                        automations. Links with the same name in different
                        emails count together.
                    </p>
                    <p
                        v-if="
                            normalizedName && normalizedName !== linkName.trim()
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Saved as
                        <span class="font-(family-name:--bm-font-mono)">{{
                            normalizedName
                        }}</span>
                    </p>
                </div>

                <p class="text-xs text-muted-foreground">
                    If the link has no UTM parameters, we'll automatically add
                    <span class="font-mono">utm_source</span> (step subject),
                    <span class="font-mono">utm_medium=mail</span>, and
                    <span class="font-mono">utm_campaign</span> (sequence name)
                    when the email is sent.
                </p>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button :disabled="!linkUrl.trim()" @click="submit">
                    Insert link
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
