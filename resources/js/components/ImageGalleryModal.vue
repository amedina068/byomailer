<script setup lang="ts">
import { ImagePlus, Upload } from 'lucide-vue-next';
import { ref, watch } from 'vue';

import {
    index as fetchGallery,
    store as uploadToGallery,
} from '@/actions/App/Http/Controllers/GalleryImageController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface GalleryImage {
    uuid: string;
    name: string;
    url: string;
    created_at: string;
}

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    select: [url: string, alt: string];
}>();

const images = ref<GalleryImage[]>([]);
const loading = ref(false);
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const getXsrfToken = (): string => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const loadImages = async (): Promise<void> => {
    loading.value = true;

    try {
        const response = await fetch(fetchGallery().url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });

        if (response.ok) {
            const data = await response.json();
            images.value = data.images;
        }
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.isOpen,
    (open) => {
        if (open) {
            uploadError.value = null;
            loadImages();
        }
    },
);

const selectImage = (image: GalleryImage): void => {
    const alt = window.prompt(
        'Image description (for accessibility and plain text emails):',
        image.name,
    );

    emit('select', image.url, alt ?? '');
    emit('update:isOpen', false);
};

const triggerUpload = (): void => {
    fileInput.value?.click();
};

const handleUpload = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    uploadError.value = null;

    try {
        const formData = new FormData();
        formData.append('image', file);

        const response = await fetch(uploadToGallery().url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-XSRF-TOKEN': getXsrfToken(),
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message ?? 'Failed to upload image');
        }

        const newImage: GalleryImage = await response.json();
        images.value = [newImage, ...images.value];
    } catch (error) {
        uploadError.value =
            error instanceof Error ? error.message : 'Failed to upload image';
    } finally {
        uploading.value = false;
        input.value = '';
    }
};
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Image gallery</DialogTitle>
            </DialogHeader>

            <div
                v-if="uploadError"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
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

            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Select an image to insert it into the editor.
                </p>

                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="uploading"
                    @click="triggerUpload"
                >
                    <Upload class="mr-1.5 h-4 w-4" />
                    {{ uploading ? 'Uploading...' : 'Upload image' }}
                </Button>
            </div>

            <div
                v-if="loading"
                class="grid grid-cols-3 gap-3 sm:grid-cols-4"
            >
                <div
                    v-for="i in 8"
                    :key="i"
                    class="aspect-square animate-pulse rounded-lg bg-muted"
                />
            </div>

            <div
                v-else-if="images.length > 0"
                class="max-h-96 overflow-y-auto"
            >
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <button
                        v-for="image in images"
                        :key="image.uuid"
                        type="button"
                        class="group relative aspect-square overflow-hidden rounded-lg border border-border bg-muted transition hover:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :title="image.name"
                        @click="selectImage(image)"
                    >
                        <img
                            :src="image.url"
                            :alt="image.name"
                            class="h-full w-full object-cover transition group-hover:scale-105"
                        />
                        <div
                            class="absolute inset-0 flex items-end bg-gradient-to-t from-black/60 to-transparent p-2 opacity-0 transition group-hover:opacity-100"
                        >
                            <span
                                class="truncate text-xs font-medium text-white"
                            >
                                {{ image.name }}
                            </span>
                        </div>
                    </button>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border py-12 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-muted"
                >
                    <ImagePlus class="h-6 w-6 text-muted-foreground" />
                </div>
                <div>
                    <p class="text-sm font-medium">No images yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Upload your first image to get started.
                    </p>
                </div>
                <Button variant="secondary" size="sm" @click="triggerUpload">
                    <Upload class="mr-1.5 h-4 w-4" />
                    Upload image
                </Button>
            </div>

            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                class="hidden"
                @change="handleUpload"
            />
        </DialogContent>
    </Dialog>
</template>
