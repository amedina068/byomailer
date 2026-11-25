<script setup lang="ts">
import { CheckIcon, CopyIcon } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority?: number | null;
    verified_at?: string | null;
}

const props = defineProps<{
    records: DnsRecord[];
    showStatus?: boolean;
}>();

const copiedKey = ref<string | null>(null);
const announcement = ref('');

const normalizedRecords = computed(() => props.records ?? []);

const markCopied = (key: string, label: string): void => {
    copiedKey.value = key;
    announcement.value = `Copied ${label}`;
    setTimeout(() => {
        copiedKey.value = null;
        announcement.value = '';
    }, 2000);
};

const copyToClipboard = async (
    text: string,
    key: string,
    label: string,
): Promise<void> => {
    try {
        await navigator.clipboard.writeText(text);
        markCopied(key, label);
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        markCopied(key, label);
    }
};
</script>

<template>
    <div class="overflow-hidden rounded-lg border">
        <span class="sr-only" aria-live="polite">{{ announcement }}</span>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Type</th>
                        <th class="px-4 py-3 text-left font-medium">Name</th>
                        <th class="px-4 py-3 text-left font-medium">
                            Priority
                        </th>
                        <th class="px-4 py-3 text-left font-medium">Value</th>
                        <th
                            v-if="showStatus"
                            class="px-4 py-3 text-left font-medium"
                        >
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="(record, index) in normalizedRecords"
                        :key="index"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ record.type }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span
                                    class="max-w-[180px] truncate font-mono text-xs"
                                    :title="record.name"
                                >
                                    {{ record.name }}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-6 w-6 shrink-0"
                                    :aria-label="`Copy ${record.type} name`"
                                    @click="
                                        copyToClipboard(
                                            record.name,
                                            `${index}-name`,
                                            `${record.type} name`,
                                        )
                                    "
                                >
                                    <CheckIcon
                                        v-if="copiedKey === `${index}-name`"
                                        class="h-3 w-3 text-green-500"
                                    />
                                    <CopyIcon v-else class="h-3 w-3" />
                                </Button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    typeof record.priority === 'number'
                                        ? record.priority
                                        : '—'
                                }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span
                                    class="max-w-[180px] truncate font-mono text-xs"
                                    :title="record.value"
                                >
                                    {{ record.value }}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-6 w-6 shrink-0"
                                    :aria-label="`Copy ${record.type} value`"
                                    @click="
                                        copyToClipboard(
                                            record.value,
                                            `${index}-value`,
                                            `${record.type} value`,
                                        )
                                    "
                                >
                                    <CheckIcon
                                        v-if="copiedKey === `${index}-value`"
                                        class="h-3 w-3 text-green-500"
                                    />
                                    <CopyIcon v-else class="h-3 w-3" />
                                </Button>
                            </div>
                        </td>
                        <td
                            v-if="showStatus"
                            class="px-4 py-3 text-xs font-medium"
                        >
                            <span
                                :class="[
                                    record.verified_at
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-amber-600 dark:text-amber-400',
                                ]"
                            >
                                {{
                                    record.verified_at ? 'Verified' : 'Pending'
                                }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            v-if="showStatus"
            class="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground"
        >
            <span class="font-medium text-green-600 dark:text-green-400"
                >Verified</span
            >
            records have been detected by SES. Records marked as
            <span class="font-medium text-amber-600 dark:text-amber-400"
                >Pending</span
            >
            still need to propagate.
        </div>
    </div>
</template>
