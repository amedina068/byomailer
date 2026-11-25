<script setup lang="ts">
import { computed } from 'vue';

interface DataPoint {
    day: string;
    new: number;
    unsubscribed: number;
    net: number;
}

const props = defineProps<{
    data: DataPoint[];
}>();

const chartWidth = 900;
const chartHeight = 160;
const paddingX = 30;
const paddingY = 10;

const maxValue = computed(() => {
    const values = props.data.flatMap((d) => [
        d.new,
        d.unsubscribed,
        Math.abs(d.net),
    ]);
    return Math.max(...values, 1);
});

const xStep = computed(() => {
    if (props.data.length <= 1) {
        return 0;
    }
    return (chartWidth - paddingX * 2) / (props.data.length - 1);
});

const toY = (value: number): number => {
    return (
        paddingY +
        (1 - Math.abs(value) / maxValue.value) * (chartHeight - paddingY * 2)
    );
};

const toX = (index: number): number => {
    if (props.data.length === 1) {
        return chartWidth / 2;
    }
    return paddingX + index * xStep.value;
};

const bottomY = chartHeight - paddingY;

const buildSmoothPath = (key: 'new' | 'unsubscribed' | 'net'): string => {
    if (props.data.length === 0) {
        return '';
    }

    const points = props.data.map((d, i) => ({
        x: toX(i),
        y: toY(d[key]),
    }));

    if (points.length === 1) {
        return `M${points[0].x},${points[0].y}`;
    }

    let path = `M${points[0].x},${points[0].y}`;

    for (let i = 0; i < points.length - 1; i++) {
        const curr = points[i];
        const next = points[i + 1];
        const tension = 0.3;
        const dx = (next.x - curr.x) * tension;

        path += ` C${curr.x + dx},${curr.y} ${next.x - dx},${next.y} ${next.x},${next.y}`;
    }

    return path;
};

const buildAreaPath = (key: 'new' | 'unsubscribed' | 'net'): string => {
    const linePath = buildSmoothPath(key);
    if (!linePath || props.data.length === 0) {
        return '';
    }

    const firstX = toX(0);
    const lastX = toX(props.data.length - 1);

    return `${linePath} L${lastX},${bottomY} L${firstX},${bottomY} Z`;
};

const gridLines = computed(() => {
    const lines = [];
    const steps = 4;
    for (let i = 0; i <= steps; i++) {
        const y = paddingY + (i / steps) * (chartHeight - paddingY * 2);
        const value = Math.round(maxValue.value * (1 - i / steps));
        lines.push({ y, value });
    }
    return lines;
});

const totals = computed(() => ({
    new: props.data.reduce((sum, d) => sum + d.new, 0),
    unsubscribed: props.data.reduce((sum, d) => sum + d.unsubscribed, 0),
    net: props.data.reduce((sum, d) => sum + d.net, 0),
}));

const series = computed(() => [
    { key: 'new' as const, color: '#10b981', id: 'new', label: 'New' },
    {
        key: 'unsubscribed' as const,
        color: '#ef4444',
        id: 'unsubs',
        label: 'Unsubs',
    },
    { key: 'net' as const, color: '#3b82f6', id: 'net', label: 'Net' },
]);
</script>

<template>
    <div
        class="w-full min-w-0 p-6"
        style="background-color: var(--bm-surface); border: 1px solid var(--bm-border); border-radius: var(--bm-radius-md); box-shadow: var(--bm-shadow-xs);"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold" style="color: var(--bm-text-1);">
                    Contact growth
                </h2>
                <p class="text-sm" style="color: var(--bm-text-2);">
                    Last 7 days
                </p>
            </div>
            <div class="flex gap-5">
                <div class="flex items-center gap-1.5 text-sm">
                    <span
                        class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"
                    />
                    <span style="color: var(--bm-text-3);">New</span>
                    <span class="font-semibold" style="color: var(--bm-text-1);">{{
                        totals.new
                    }}</span>
                </div>
                <div class="flex items-center gap-1.5 text-sm">
                    <span
                        class="inline-block h-2.5 w-2.5 rounded-full bg-red-500"
                    />
                    <span style="color: var(--bm-text-3);">Unsubs</span>
                    <span class="font-semibold" style="color: var(--bm-text-1);">{{
                        totals.unsubscribed
                    }}</span>
                </div>
                <div class="flex items-center gap-1.5 text-sm">
                    <span
                        class="inline-block h-2.5 w-2.5 rounded-full bg-blue-500"
                    />
                    <span style="color: var(--bm-text-3);">Net</span>
                    <span class="font-semibold" style="color: var(--bm-text-1);">{{
                        totals.net
                    }}</span>
                </div>
            </div>
        </div>

        <div v-if="data.length > 0" class="mt-6">
            <svg
                :viewBox="`0 0 ${chartWidth} ${chartHeight + 20}`"
                preserveAspectRatio="xMidYMid meet"
                class="block h-auto w-full max-w-full"
            >
                <defs>
                    <linearGradient
                        v-for="s in series"
                        :id="`gradient-${s.id}`"
                        :key="'grad-' + s.id"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            :stop-color="s.color"
                            stop-opacity="0.25"
                        />
                        <stop
                            offset="100%"
                            :stop-color="s.color"
                            stop-opacity="0"
                        />
                    </linearGradient>
                </defs>

                <!-- Grid lines -->
                <g v-for="line in gridLines" :key="line.y">
                    <line
                        :x1="paddingX"
                        :y1="line.y"
                        :x2="chartWidth - paddingX"
                        :y2="line.y"
                        stroke-width="0.5"
                        style="stroke: var(--bm-border);"
                    />
                    <text
                        :x="paddingX - 6"
                        :y="line.y + 3"
                        text-anchor="end"
                        style="fill: var(--bm-text-3);"
                        font-size="7"
                    >
                        {{ line.value }}
                    </text>
                </g>

                <!-- Area fills -->
                <path
                    v-for="s in series"
                    :key="'area-' + s.key"
                    :d="buildAreaPath(s.key)"
                    :fill="`url(#gradient-${s.id})`"
                />

                <!-- Smooth lines -->
                <path
                    v-for="s in series"
                    :key="'line-' + s.key"
                    :d="buildSmoothPath(s.key)"
                    fill="none"
                    :stroke="s.color"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <!-- Dots -->
                <template v-for="s in series" :key="'dots-' + s.key">
                    <circle
                        v-for="(point, i) in data"
                        :key="i"
                        :cx="toX(i)"
                        :cy="toY(point[s.key])"
                        r="3.5"
                        :fill="s.color"
                        stroke="white"
                        stroke-width="1.5"
                    >
                        <title>{{ s.label }}: {{ point[s.key] }}</title>
                    </circle>
                </template>

                <!-- Day labels -->
                <text
                    v-for="(point, i) in data"
                    :key="'label-' + i"
                    :x="toX(i)"
                    :y="chartHeight + 14"
                    text-anchor="middle"
                    style="fill: var(--bm-text-3);"
                    font-size="8"
                >
                    {{ point.day }}
                </text>
            </svg>
        </div>

        <div
            v-else
            class="mt-6 flex items-center justify-center"
            style="height: 160px"
        >
            <p class="text-sm" style="color: var(--bm-text-3);">
                No contact activity this week yet.
            </p>
        </div>
    </div>
</template>
