<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhTrophy } from '@phosphor-icons/vue';
import { ref } from 'vue';

import NewsletterAbWinnerController from '@/actions/App/Http/Controllers/NewsletterAbWinnerController';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { toast } from '@/composables/useToast';

type Variant = 'a' | 'b';

interface AbVariantMetrics {
    total: number;
    opened_count: number;
    clicked_count: number;
    open_rate: number | null;
    click_rate: number | null;
}

const props = defineProps<{
    newsletterId: number;
    status: string;
    winnerVariant: string | null;
    metrics: Record<Variant, AbVariantMetrics>;
}>();

const variants: Variant[] = ['a', 'b'];

const formatRate = (rate: number | null): string =>
    rate !== null ? `${rate}%` : '—';

const stats = (variant: Variant): Array<{ label: string; value: string }> => {
    const metrics = props.metrics[variant];

    return [
        { label: 'Recipients', value: metrics.total.toLocaleString() },
        { label: 'Opens', value: metrics.opened_count.toLocaleString() },
        { label: 'Open rate', value: formatRate(metrics.open_rate) },
        { label: 'Click rate', value: formatRate(metrics.click_rate) },
    ];
};

const pickingWinner = ref<Variant | null>(null);
const winnerToConfirm = ref<Variant | null>(null);
const confirmOpen = ref(false);

const askToPickWinner = (variant: Variant): void => {
    winnerToConfirm.value = variant;
    confirmOpen.value = true;
};

const pickWinner = (): void => {
    const variant = winnerToConfirm.value;

    if (variant === null || pickingWinner.value !== null) {
        return;
    }

    confirmOpen.value = false;
    pickingWinner.value = variant;

    router.post(
        NewsletterAbWinnerController(props.newsletterId).url,
        { winner_variant: variant },
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(
                    errors.status ??
                        Object.values(errors)[0] ??
                        'The winner was not saved.',
                );
            },
            onFinish: () => {
                pickingWinner.value = null;
            },
        },
    );
};
</script>

<template>
    <div
        class="rounded-[var(--bm-radius-md)] border p-5"
        style="
            background-color: var(--bm-surface);
            border-color: var(--bm-border);
            box-shadow: var(--bm-shadow-xs);
        "
    >
        <p
            class="text-[10px] font-semibold tracking-widest uppercase"
            style="color: var(--bm-text-3)"
        >
            A/B test results
        </p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div
                v-for="variant in variants"
                :key="variant"
                class="rounded-[var(--bm-radius)] border p-4"
                :style="{
                    borderColor:
                        winnerVariant === variant
                            ? 'var(--bm-success)'
                            : 'var(--bm-border)',
                    backgroundColor:
                        winnerVariant === variant
                            ? 'var(--bm-success-dim)'
                            : 'var(--bm-base)',
                }"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <p
                            class="text-sm font-semibold"
                            style="color: var(--bm-text-1)"
                        >
                            Variant {{ variant.toUpperCase() }}
                        </p>
                        <span
                            v-if="winnerVariant === variant"
                            class="inline-flex items-center gap-1 px-1.5 py-0.5"
                            style="
                                background-color: var(--bm-success-dim);
                                color: var(--bm-success);
                                font-family: var(--bm-font-mono);
                                font-size: 10px;
                                font-weight: 500;
                                border-radius: 3px;
                            "
                        >
                            <PhTrophy :size="11" weight="fill" />
                            Winner
                        </span>
                    </div>
                    <Button
                        v-if="status === 'ab_testing' && !winnerVariant"
                        size="sm"
                        variant="outline"
                        :disabled="pickingWinner !== null"
                        @click="askToPickWinner(variant)"
                    >
                        {{
                            pickingWinner === variant
                                ? 'Picking…'
                                : 'Pick winner'
                        }}
                    </Button>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-3">
                    <div v-for="stat in stats(variant)" :key="stat.label">
                        <dt class="text-xs" style="color: var(--bm-text-3)">
                            {{ stat.label }}
                        </dt>
                        <dd
                            class="text-sm font-semibold tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ stat.value }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <ConfirmDialog
            v-model:is-open="confirmOpen"
            :title="`Pick variant ${winnerToConfirm?.toUpperCase()} as the winner?`"
            description="The newsletter gets the status sent. This sends no more email. You cannot change the winner after this step."
            :confirm-label="`Pick variant ${winnerToConfirm?.toUpperCase()}`"
            variant="default"
            :processing="pickingWinner !== null"
            @confirm="pickWinner"
        />
    </div>
</template>
