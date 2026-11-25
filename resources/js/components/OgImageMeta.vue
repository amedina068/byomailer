<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const ogImage = computed<string | null>(() => page.props.ogImage ?? null);
</script>

<template>
    <Head v-if="ogImage">
        <meta head-key="og:image" property="og:image" :content="ogImage" />
        <meta
            head-key="twitter:image"
            name="twitter:image"
            :content="ogImage"
        />
        <meta
            head-key="twitter:card"
            name="twitter:card"
            content="summary_large_image"
        />
    </Head>
</template>
