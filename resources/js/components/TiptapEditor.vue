<script setup lang="ts">
import {
    PhArrowClockwise,
    PhArrowCounterClockwise,
    PhCode,
    PhCodeBlock,
    PhImage,
    PhLink,
    PhLinkBreak,
    PhListBullets,
    PhListNumbers,
    PhMinus,
    PhPuzzlePiece,
    PhQuotes,
    PhTextAlignCenter,
    PhTextAlignLeft,
    PhTextAlignRight,
    PhTextB,
    PhTextItalic,
    PhTextStrikethrough,
    PhTextUnderline,
} from '@phosphor-icons/vue';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { computed, onMounted, ref, watch } from 'vue';

import { store as uploadImage } from '@/actions/App/Http/Controllers/EditorImageController';
import ImageGalleryModal from '@/components/ImageGalleryModal.vue';
import InsertBlockModal from '@/components/InsertBlockModal.vue';
import InsertLinkModal from '@/components/InsertLinkModal.vue';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import BlockTypeMenu from '@/components/tiptap/BlockTypeMenu.vue';
import ColorMenu from '@/components/tiptap/ColorMenu.vue';
import { ContentSlotBadge } from '@/components/tiptap/ContentSlotBadge';
import { inlineElementDefaults } from '@/components/tiptap/elementDefaults';
import FontFamilyMenu from '@/components/tiptap/FontFamilyMenu.vue';
import FontSizeMenu from '@/components/tiptap/FontSizeMenu.vue';
import { TrackedLink } from '@/components/tiptap/TrackedLink';
import { TYPOGRAPHY_EXTENSIONS } from '@/components/tiptap/typography';
import {
    hasContentPlaceholder,
    splitTemplateAtSlot,
    unwrapPlaceholderForStorage,
    wrapPlaceholderForEditor,
} from '@/lib/template-slot';

type EditorMode = 'plain' | 'template';

interface TemplateChrome {
    html_content: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
        mode?: EditorMode;
        template?: TemplateChrome | null;
    }>(),
    {
        mode: 'plain',
        template: null,
    },
);

const templateChrome = computed(() => {
    if (!props.template) {
        return null;
    }
    return splitTemplateAtSlot(props.template.html_content);
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'insert-placeholder-disabled-changed', disabled: boolean): void;
}>();

const toEditorHtml = (html: string): string =>
    props.mode === 'template' ? wrapPlaceholderForEditor(html) : html;

/** Gives an icon-only toolbar button a tooltip and an accessible name. */
const toolbarLabel = (label: string) => ({ title: label, 'aria-label': label });

const fromEditorHtml = (html: string): string => {
    const inlined = inlineElementDefaults(html);

    if (props.mode === 'template') {
        return unwrapPlaceholderForStorage(inlined);
    }

    return inlined;
};

const uploading = ref(false);
const uploadError = ref<string | null>(null);
const isLinkModalOpen = ref(false);
const isGalleryModalOpen = ref(false);
const isBlockModalOpen = ref(false);

const extensions = [
    StarterKit.configure({
        heading: {
            levels: [1, 2, 3],
        },
        link: false,
    }),
    Underline,
    Image.configure({
        HTMLAttributes: {
            class: 'max-w-full h-auto rounded-lg',
        },
    }),
    TrackedLink.configure({
        openOnClick: false,
        HTMLAttributes: {
            style: 'color: #2563eb; text-decoration: underline',
        },
    }),
    TextAlign.configure({
        types: ['heading', 'paragraph'],
    }),
    Placeholder.configure({
        placeholder: props.placeholder ?? 'Write your content here...',
    }),
    ...TYPOGRAPHY_EXTENSIONS,
];

if (props.mode === 'template') {
    extensions.push(ContentSlotBadge);
}

const editor = useEditor({
    content: toEditorHtml(props.modelValue),
    editable: !props.disabled,
    extensions,
    editorProps: {
        attributes: {
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': props.ariaLabel ?? 'Content',
        },
    },
    onUpdate: ({ editor }) => {
        emit('update:modelValue', fromEditorHtml(editor.getHTML()));
    },
});

const hasBadge = (): boolean => {
    if (!editor.value) {
        return false;
    }

    let found = false;

    editor.value.state.doc.descendants((node) => {
        if (node.type.name === 'contentSlotBadge') {
            found = true;

            return false;
        }

        return true;
    });

    return found;
};

const insertPlaceholder = (): void => {
    if (!editor.value || props.disabled) {
        return;
    }

    if (hasBadge()) {
        return;
    }

    editor.value.chain().focus().insertContentSlotBadge().run();
};

watch(
    () => props.modelValue,
    (value) => {
        if (!editor.value) {
            return;
        }

        const currentSerialized = fromEditorHtml(editor.value.getHTML());

        if (currentSerialized !== value) {
            editor.value.commands.setContent(toEditorHtml(value), {
                emitUpdate: false,
            });
        }
    },
);

watch(
    () => props.disabled,
    (disabled) => {
        if (editor.value) {
            editor.value.setEditable(!disabled);
        }
    },
);

onMounted(() => {
    if (editor.value && props.modelValue) {
        editor.value.commands.setContent(toEditorHtml(props.modelValue), {
            emitUpdate: false,
        });
    }
});

const insertPlaceholderDisabled = (): boolean => {
    if (props.mode !== 'template') {
        return true;
    }

    return hasBadge();
};

const placeholderAlreadyPresent = (): boolean =>
    props.mode === 'template' && hasContentPlaceholder(props.modelValue);

defineExpose({
    insertPlaceholder,
    insertPlaceholderDisabled,
    placeholderAlreadyPresent,
});

const openLinkModal = (): void => {
    isLinkModalOpen.value = Boolean(editor.value) && !props.disabled;
};

const triggerImageUpload = (): void => {
    if (props.disabled) {
        return;
    }

    isGalleryModalOpen.value = true;
};

const handleGallerySelect = (url: string, alt: string): void => {
    if (!editor.value) {
        return;
    }

    editor.value.chain().focus().setImage({ src: url, alt }).run();
};

const handleBlockSelect = (block: { name: string }): void => {
    if (!editor.value) {
        return;
    }

    editor.value
        .chain()
        .focus()
        .insertContent(`{{ block.${block.name} }}`)
        .run();
};

const handleDrop = async (event: DragEvent): Promise<void> => {
    const file = event.dataTransfer?.files[0];

    if (!file || !file.type.startsWith('image/') || !editor.value) {
        return;
    }

    event.preventDefault();

    uploading.value = true;
    uploadError.value = null;

    try {
        const formData = new FormData();
        formData.append('image', file);

        const xsrfToken = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        const response = await fetch(uploadImage().url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-XSRF-TOKEN': xsrfToken ? decodeURIComponent(xsrfToken) : '',
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message ?? 'Failed to upload image');
        }

        const data = await response.json();

        const alt = window.prompt(
            'Image description (for accessibility and plain text emails):',
            file.name.replace(/\.[^/.]+$/, ''),
        );

        editor.value
            .chain()
            .focus()
            .setImage({ src: data.url, alt: alt ?? '' })
            .run();
    } catch (error) {
        uploadError.value =
            error instanceof Error ? error.message : 'Failed to upload image';
    } finally {
        uploading.value = false;
    }
};
</script>

<template>
    <div class="tiptap-editor rounded-lg border border-border bg-background">
        <div
            v-if="editor"
            class="flex flex-wrap items-center gap-1 border-b border-border p-2"
        >
            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('bold')"
                :disabled="disabled"
                v-bind="toolbarLabel('Bold')"
                @click="editor.chain().focus().toggleBold().run()"
            >
                <PhTextB weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('italic')"
                :disabled="disabled"
                v-bind="toolbarLabel('Italic')"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                <PhTextItalic weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('underline')"
                :disabled="disabled"
                v-bind="toolbarLabel('Underline')"
                @click="editor.chain().focus().toggleUnderline().run()"
            >
                <PhTextUnderline weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('strike')"
                :disabled="disabled"
                v-bind="toolbarLabel('Strikethrough')"
                @click="editor.chain().focus().toggleStrike().run()"
            >
                <PhTextStrikethrough weight="regular" class="h-4 w-4" />
            </button>

            <div class="mx-1 h-6 w-px bg-border"></div>

            <BlockTypeMenu :editor="editor" :disabled="disabled ?? false" />

            <div class="mx-1 h-6 w-px bg-border"></div>

            <FontFamilyMenu :editor="editor" :disabled="disabled ?? false" />
            <FontSizeMenu :editor="editor" :disabled="disabled ?? false" />
            <ColorMenu :editor="editor" :disabled="disabled ?? false" />

            <div class="mx-1 h-6 w-px bg-border"></div>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('bulletList')"
                :disabled="disabled"
                v-bind="toolbarLabel('Bullet list')"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                <PhListBullets weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('orderedList')"
                :disabled="disabled"
                v-bind="toolbarLabel('Numbered list')"
                @click="editor.chain().focus().toggleOrderedList().run()"
            >
                <PhListNumbers weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('blockquote')"
                :disabled="disabled"
                v-bind="toolbarLabel('Blockquote')"
                @click="editor.chain().focus().toggleBlockquote().run()"
            >
                <PhQuotes weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('codeBlock')"
                :disabled="disabled"
                v-bind="toolbarLabel('Code block')"
                @click="editor.chain().focus().toggleCodeBlock().run()"
            >
                <PhCode weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled"
                v-bind="toolbarLabel('Horizontal rule')"
                @click="editor.chain().focus().setHorizontalRule().run()"
            >
                <PhMinus weight="regular" class="h-4 w-4" />
            </button>

            <div class="mx-1 h-6 w-px bg-border"></div>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive({ textAlign: 'left' })"
                :disabled="disabled"
                v-bind="toolbarLabel('Align left')"
                @click="editor.chain().focus().setTextAlign('left').run()"
            >
                <PhTextAlignLeft weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive({ textAlign: 'center' })"
                :disabled="disabled"
                v-bind="toolbarLabel('Align center')"
                @click="editor.chain().focus().setTextAlign('center').run()"
            >
                <PhTextAlignCenter weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive({ textAlign: 'right' })"
                :disabled="disabled"
                v-bind="toolbarLabel('Align right')"
                @click="editor.chain().focus().setTextAlign('right').run()"
            >
                <PhTextAlignRight weight="regular" class="h-4 w-4" />
            </button>

            <div class="mx-1 h-6 w-px bg-border"></div>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted aria-pressed:bg-muted"
                :aria-pressed="editor.isActive('link')"
                :disabled="disabled"
                v-bind="toolbarLabel('Add link')"
                @click="openLinkModal"
            >
                <PhLink weight="regular" class="h-4 w-4" />
            </button>

            <button
                v-if="editor.isActive('link')"
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled"
                v-bind="toolbarLabel('Remove link')"
                @click="editor.chain().focus().unsetLink().run()"
            >
                <PhLinkBreak weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :class="{ 'opacity-50': uploading }"
                :disabled="disabled || uploading"
                v-bind="toolbarLabel('Insert image')"
                @click="triggerImageUpload"
            >
                <PhImage weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled"
                v-bind="toolbarLabel('Insert block')"
                @click="isBlockModalOpen = true"
            >
                <PhPuzzlePiece weight="regular" class="h-4 w-4" />
            </button>

            <button
                v-if="mode === 'template'"
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled || hasBadge()"
                v-bind="toolbarLabel('Insert content placeholder')"
                @click="insertPlaceholder"
            >
                <PhCodeBlock weight="regular" class="h-4 w-4" />
            </button>

            <div class="mx-1 h-6 w-px bg-border"></div>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled || !editor.can().undo()"
                v-bind="toolbarLabel('Undo')"
                @click="editor.chain().focus().undo().run()"
            >
                <PhArrowCounterClockwise weight="regular" class="h-4 w-4" />
            </button>

            <button
                type="button"
                class="rounded p-1.5 transition hover:bg-muted"
                :disabled="disabled || !editor.can().redo()"
                v-bind="toolbarLabel('Redo')"
                @click="editor.chain().focus().redo().run()"
            >
                <PhArrowClockwise weight="regular" class="h-4 w-4" />
            </button>

            <span v-if="uploading" class="ml-2 text-xs text-muted-foreground">
                Uploading image...
            </span>
        </div>

        <div
            v-if="uploadError"
            class="border-b border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
        >
            {{ uploadError }}
            <button
                type="button"
                class="ml-2 underline"
                @click="uploadError = null"
            >
                Dismiss
            </button>
        </div>

        <div class="p-3">
            <div
                class="overflow-hidden rounded-md border border-border/40 bg-white"
            >
                <div
                    v-if="templateChrome?.hasSlot"
                    class="border-b border-dashed border-border/60"
                    data-template-chrome="before"
                >
                    <HtmlPreview
                        :html="templateChrome.before"
                        :min-height="40"
                    />
                </div>

                <EditorContent
                    :editor="editor"
                    class="max-w-none p-4"
                    @drop="handleDrop"
                    @dragover.prevent
                />

                <div
                    v-if="templateChrome?.hasSlot"
                    class="border-t border-dashed border-border/60"
                    data-template-chrome="after"
                >
                    <HtmlPreview
                        :html="templateChrome.after"
                        :min-height="40"
                    />
                </div>
            </div>
        </div>

        <InsertLinkModal
            :is-open="isLinkModalOpen"
            :editor="editor"
            @update:is-open="(open: boolean) => (isLinkModalOpen = open)"
        />

        <ImageGalleryModal
            :is-open="isGalleryModalOpen"
            @update:is-open="(open: boolean) => (isGalleryModalOpen = open)"
            @select="handleGallerySelect"
        />

        <InsertBlockModal
            :is-open="isBlockModalOpen"
            @update:is-open="(open: boolean) => (isBlockModalOpen = open)"
            @select="handleBlockSelect"
        />
    </div>
</template>

<style>
/* Anchor the editor body to an email-safe sans default so inline text without
   an explicit font-family does not inherit the app's General Sans webfont and
   diverge from the email/iframe preview. */
.tiptap-editor .ProseMirror {
    outline: none;
    color: #1f2937;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 16px;
    line-height: 1.5;
}

.tiptap-editor .ProseMirror p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: #9ca3af;
    pointer-events: none;
    height: 0;
}

.tiptap-editor .ProseMirror img {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1rem 0;
}

.tiptap-editor .ProseMirror blockquote {
    border-left: 3px solid #e5e7eb;
    padding-left: 1rem;
    margin-left: 0;
    font-style: italic;
}

/* Per-element typography defaults — keep in sync with elementDefaults.ts */
.tiptap-editor .ProseMirror p {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 16px;
    color: #1f2937;
    font-weight: 400;
    margin-top: 0;
    margin-bottom: 16px;
}

.tiptap-editor .ProseMirror ul,
.tiptap-editor .ProseMirror ol {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 16px;
    color: #1f2937;
    margin-top: 0;
    margin-bottom: 16px;
}

.tiptap-editor .ProseMirror blockquote {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16px;
    color: #4b5563;
}

.tiptap-editor .ProseMirror code {
    background-color: #f3f4f6;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.tiptap-editor .ProseMirror pre {
    background-color: #f3f4f6;
    padding: 1rem;
    border-radius: 0.5rem;
    overflow-x: auto;
}

.tiptap-editor .ProseMirror pre code {
    background: none;
    padding: 0;
}

.tiptap-editor .ProseMirror hr {
    border: none;
    border-top: 1px solid #9ca3af;
    margin: 1.5rem 0;
}

.tiptap-editor .ProseMirror ul,
.tiptap-editor .ProseMirror ol {
    padding-left: 1.5rem;
}

.tiptap-editor .ProseMirror ul {
    list-style-type: disc;
}

.tiptap-editor .ProseMirror ol {
    list-style-type: decimal;
}

.tiptap-editor .ProseMirror h1 {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 32px;
    color: #111827;
    font-weight: 700;
    margin-top: 24px;
    margin-bottom: 12px;
}

.tiptap-editor .ProseMirror h2 {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 24px;
    color: #111827;
    font-weight: 700;
    margin-top: 20px;
    margin-bottom: 10px;
}

.tiptap-editor .ProseMirror h3 {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 20px;
    color: #111827;
    font-weight: 600;
    margin-top: 16px;
    margin-bottom: 8px;
}

.tiptap-editor .ProseMirror a {
    color: #2563eb;
    text-decoration: underline;
}

.tiptap-editor .tiptap-content-slot-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    background-color: #fef3c7;
    color: #78350f;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 0.8125rem;
    font-weight: 500;
    border: 1px dashed #f59e0b;
    cursor: default;
}
</style>
