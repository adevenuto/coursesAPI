<script setup lang="ts">
import { computed } from 'vue';
import { useChartTheme } from '@/components/charts/useChartTheme';
import { shortDate } from '@/lib/format';

/**
 * Daily request volume for the signed-in user.
 *
 * Props are unchanged — this component is on the page users see most, so its
 * shape stays put. Only the internals moved onto useChartTheme, which fixes a
 * real bug: the previous isDark() read document.documentElement.classList inside
 * a computed, which Vue can't track, so switching theme left the chart on the
 * old palette until it happened to remount.
 */
const props = withDefaults(
    defineProps<{
        series: { date: string; requests: number }[];
        height?: number;
    }>(),
    { height: 220 },
);

const { chart, baseOptions, palette } = useChartTheme();

const chartSeries = computed(() => [
    { name: 'Requests', data: props.series.map((d) => d.requests) },
]);

const chartOptions = computed(() => ({
    ...baseOptions.value,
    chart: { ...baseOptions.value.chart, type: 'area' },
    colors: [palette.ok],
    stroke: { curve: 'smooth', width: 2 },
    fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] },
    },
    xaxis: {
        ...baseOptions.value.xaxis,
        categories: props.series.map((d) => shortDate(d.date)),
    },
    legend: { show: false },
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
