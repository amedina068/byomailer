<script setup lang="ts">
import { Copy, Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import CodeBlock from '@/components/documentation/CodeBlock.vue';
import FieldList from '@/components/documentation/FieldList.vue';
import type { Field } from '@/components/documentation/FieldList.vue';
import TabbedCodeBlock from '@/components/documentation/TabbedCodeBlock.vue';
import { Button } from '@/components/ui/button';
import { useAppUrl } from '@/composables/useAppUrl';
import { useRequestExamples } from '@/composables/useRequestExamples';

export type { Field as ParamField };

export interface Endpoint {
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    path: string;
    description: string;
    pathParams?: Field[];
    bodyFields?: Field[];
    responseFields?: Field[];
    requestExample?: Record<string, unknown>;
    response: {
        status: number;
        body: Record<string, unknown>;
    };
}

const props = defineProps<{
    endpoint: Endpoint;
    id: string;
}>();

const formatJson = (obj: Record<string, unknown>): string => {
    return JSON.stringify(obj, null, 2);
};

const appUrl = useAppUrl();

const requestExamples = computed(() => {
    return useRequestExamples({
        method: props.endpoint.method,
        path: props.endpoint.path,
        body: props.endpoint.requestExample,
        baseUrl: appUrl.value,
    });
});

const methodColors: Record<string, string> = {
    GET: 'text-emerald-600 dark:text-emerald-400',
    POST: 'text-blue-600 dark:text-blue-400',
    PUT: 'text-amber-600 dark:text-amber-400',
    PATCH: 'text-orange-600 dark:text-orange-400',
    DELETE: 'text-red-600 dark:text-red-400',
};

const methodBadgeColors: Record<string, string> = {
    GET: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    POST: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    PUT: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    PATCH: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    DELETE: 'bg-red-500/10 text-red-600 dark:text-red-400',
};

const hasLeftContent = computed(() => {
    return (props.endpoint.pathParams && props.endpoint.pathParams.length > 0)
        || (props.endpoint.bodyFields && props.endpoint.bodyFields.length > 0)
        || (props.endpoint.responseFields && props.endpoint.responseFields.length > 0);
});

const hasResponse = computed(() => {
    return Object.keys(props.endpoint.response.body).length > 0;
});

const copiedPath = ref(false);

const copyPath = async () => {
    await navigator.clipboard.writeText(`${appUrl.value}${props.endpoint.path}`);
    copiedPath.value = true;
    setTimeout(() => {
        copiedPath.value = false;
    }, 2000);
};

</script>

<template>
    <div :id="id" class="border-b pb-10 last:border-b-0 last:pb-0">
        <!-- Heading: method + path + description -->
        <div class="mb-6">
            <h2 class="flex items-center gap-3">
                <span
                    class="font-mono text-sm font-bold"
                    :class="methodColors[endpoint.method]"
                >
                    {{ endpoint.method }}
                </span>
                <code class="text-sm font-medium text-foreground">
                    {{ endpoint.path }}
                </code>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                    @click="copyPath"
                >
                    <Check v-if="copiedPath" class="h-3.5 w-3.5" />
                    <Copy v-else class="h-3.5 w-3.5" />
                </Button>
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ endpoint.description }}
            </p>
        </div>

        <!-- Two-column content -->
        <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
            <!-- Left: fields -->
            <div v-if="hasLeftContent" class="min-w-0 space-y-6 lg:sticky lg:top-32">
                <FieldList
                    v-if="endpoint.pathParams && endpoint.pathParams.length > 0"
                    title="Path Parameters"
                    :fields="endpoint.pathParams"
                />

                <FieldList
                    v-if="endpoint.bodyFields && endpoint.bodyFields.length > 0"
                    title="Body Parameters"
                    :fields="endpoint.bodyFields"
                />

                <FieldList
                    v-if="endpoint.responseFields && endpoint.responseFields.length > 0"
                    title="Response Fields"
                    :fields="endpoint.responseFields"
                />
            </div>

            <!-- Right: examples -->
            <div :class="{ 'lg:col-start-2': !hasLeftContent }" class="min-w-0 space-y-6 lg:sticky lg:top-32">
                <TabbedCodeBlock
                    title="Request"
                    :tabs="requestExamples"
                />

                <CodeBlock
                    title="Response"
                    :code="hasResponse ? formatJson(endpoint.response.body) : ''"
                    :empty="!hasResponse"
                    :badge="String(endpoint.response.status)"
                    :badge-class="methodBadgeColors[endpoint.method]"
                />
            </div>
        </div>
    </div>
</template>
