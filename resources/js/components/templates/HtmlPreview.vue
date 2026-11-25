<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { buildPreviewSrcdoc } from '@/components/templates/htmlPreviewSrcdoc';

interface Props {
    html: string;
    /**
     * Minimum visible height for the iframe before content is measured. Avoids
     * a 0px flash on first render.
     */
    minHeight?: number;
}

const props = withDefaults(defineProps<Props>(), {
    minHeight: 200,
});

const container = ref<HTMLDivElement | null>(null);
const iframe = ref<HTMLIFrameElement | null>(null);
const contentHeight = ref(props.minHeight);
const isVisible = ref(false);

const srcdoc = computed(() => buildPreviewSrcdoc(props.html));

const wrapperHeight = computed(() => Math.max(props.minHeight, contentHeight.value));

let intersectionObserver: IntersectionObserver | null = null;
let contentResizeObserver: ResizeObserver | null = null;

const measureContent = (): void => {
    const doc = iframe.value?.contentDocument;
    if (!doc?.body) {
        return;
    }
    const measured = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
    if (measured > 0) {
        contentHeight.value = measured;
    }
};

const onIframeLoad = (): void => {
    measureContent();

    contentResizeObserver?.disconnect();
    const doc = iframe.value?.contentDocument;
    if (doc?.body && typeof ResizeObserver !== 'undefined') {
        contentResizeObserver = new ResizeObserver(() => measureContent());
        contentResizeObserver.observe(doc.body);
    }
};

watch(
    () => props.html,
    () => {
        requestAnimationFrame(() => measureContent());
    },
);

onMounted(() => {
    if (!container.value) {
        return;
    }

    if (typeof IntersectionObserver !== 'undefined') {
        intersectionObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        isVisible.value = true;
                        intersectionObserver?.disconnect();
                        intersectionObserver = null;
                        break;
                    }
                }
            },
            { rootMargin: '200px' },
        );
        intersectionObserver.observe(container.value);
    } else {
        isVisible.value = true;
    }
});

onBeforeUnmount(() => {
    intersectionObserver?.disconnect();
    intersectionObserver = null;
    contentResizeObserver?.disconnect();
    contentResizeObserver = null;
});
</script>

<template>
    <div
        ref="container"
        class="relative w-full bg-white"
        :style="{ height: `${wrapperHeight}px` }"
    >
        <iframe
            v-if="isVisible"
            ref="iframe"
            :srcdoc="srcdoc"
            sandbox="allow-same-origin"
            class="block h-full w-full border-0"
            aria-hidden="true"
            tabindex="-1"
            title="Email preview"
            @load="onIframeLoad"
        />
    </div>
</template>
