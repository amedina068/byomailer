<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ShieldAlertIcon } from 'lucide-vue-next';

import { index as emailCredentialsIndex } from '@/actions/App/Http/Controllers/Settings/EmailCredentialController';
import { Button } from '@/components/ui/button';

defineProps<{
    missingPermissions: string[];
    message?: string;
}>();
</script>

<template>
    <div
        v-if="missingPermissions.length > 0"
        class="flex flex-col gap-4 rounded-xl border border-red-200 bg-red-50 p-5 sm:flex-row sm:items-center dark:border-red-900 dark:bg-red-950"
    >
        <div
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-500/10"
        >
            <ShieldAlertIcon class="h-5 w-5 text-red-500 dark:text-red-400" />
        </div>
        <div class="min-w-0 grow">
            <h3 class="font-semibold text-foreground">
                Missing SES permissions
            </h3>
            <p class="text-sm text-muted-foreground">
                {{
                    message ||
                    'Your AWS credentials are missing required permissions. Some features may not work until this is resolved.'
                }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Missing:
                <code
                    v-for="(perm, i) in missingPermissions"
                    :key="perm"
                    class="rounded bg-red-100 px-1 py-0.5 text-red-700 dark:bg-red-900/50 dark:text-red-300"
                    >{{ perm
                    }}<template v-if="i < missingPermissions.length - 1"
                        >,
                    </template></code
                >
            </p>
        </div>
        <Button
            as-child
            size="sm"
            variant="outline"
            class="shrink-0 border-red-300 text-red-700 hover:bg-red-100 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/50"
        >
            <Link :href="emailCredentialsIndex().url">
                Review credentials
            </Link>
        </Button>
    </div>
</template>
