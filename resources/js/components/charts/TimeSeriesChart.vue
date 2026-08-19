<script setup lang="ts">
import { computed } from 'vue';
import { useChartTheme } from './useChartTheme';

export interface TimeSeries {
    name: string;
    color?: string;
    data: number[];
}

const props = withDefaults(
    defineProps<{
        series: TimeSeries[];
        categories: string[];
        type?: 'area' | 'line' | 'bar';
        stacked?: boolean;
        height?: number;
    }>(),
    { type: 'area', stacked: false, height: 240 },
);

const { chart, baseOptions, palette } = useChartTheme();

const options = computed(() => ({
    ...baseOptions.value,
    chart: { ...baseOptions.value.chart, type: props.type, stacked: props.stacked },
    colors: props.series.map((s, i) => s.color ?? palette.categorical[i % palette.categorical.length]),
    stroke: { curve: 'smooth', width: props.type === 'bar' ? 0 : 2 },
    fill:
        props.type === 'area'
            ? {
                  type: 'gradient',
                  gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] },
              }
            : { type: 'solid' },
    xaxis: { ...baseOptions.value.xaxis, categories: props.categories },
    // A single series doesn't need a legend telling you what it is.
    legend: { ...baseOptions.value.legend, show: props.series.length > 1 },
}));
</script>

<template>
    <component :is="chart" v-if="chart" :type="type" :height="height" :options="options" :series="series" />
    <div v-else :style="{ height: height + 'px' }" />
</template>
