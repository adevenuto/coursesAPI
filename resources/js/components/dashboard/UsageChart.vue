<script setup lang="ts">
import { computed, onMounted, shallowRef } from 'vue';

const props = withDefaults(
    defineProps<{
        series: { date: string; requests: number }[];
        height?: number;
    }>(),
    { height: 220 },
);

// Client-only import keeps ApexCharts out of SSR (it touches window).
const chart = shallowRef<unknown>(null);
onMounted(async () => {
    chart.value = (await import('vue3-apexcharts')).default;
});

const isDark = () =>
    typeof document !== 'undefined' &&
    document.documentElement.classList.contains('dark');

const chartSeries = computed(() => [
    { name: 'Requests', data: props.series.map((d) => d.requests) },
]);

const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        toolbar: { show: false },
        background: 'transparent',
        fontFamily: 'inherit',
        sparkline: { enabled: false },
    },
    theme: { mode: isDark() ? 'dark' : 'light' },
    colors: ['#22c55e'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] },
    },
    grid: { borderColor: isDark() ? '#262626' : '#e5e7eb', strokeDashArray: 4 },
    xaxis: {
        categories: props.series.map((d) =>
            new Date(d.date + 'T00:00:00').toLocaleDateString('en-US', {
                month: 'numeric',
                day: 'numeric',
            }),
        ),
        labels: { rotate: 0, style: { colors: '#9ca3af' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
        tooltip: { enabled: false },
    },
    yaxis: { labels: { style: { colors: '#9ca3af' } }, min: 0, forceNiceScale: true },
    tooltip: { theme: isDark() ? 'dark' : 'light' },
}));
</script>

<template>
    <component
        :is="chart"
        v-if="chart"
        type="area"
        :height="height"
        :options="chartOptions"
        :series="chartSeries"
    />
    <div v-else :style="{ height: height + 'px' }" />
</template>
