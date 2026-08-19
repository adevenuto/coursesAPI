<script setup lang="ts">
import { computed } from 'vue';
import { useChartTheme } from './useChartTheme';

const props = withDefaults(
    defineProps<{
        labels: string[];
        values: number[];
        colors?: string[];
        height?: number;
    }>(),
    { height: 260 },
);

const { chart, baseOptions, palette } = useChartTheme();

const options = computed(() => ({
    ...baseOptions.value,
    chart: { ...baseOptions.value.chart, type: 'donut' },
    colors: props.colors ?? palette.categorical,
    labels: props.labels,
    stroke: { width: 0 },
    legend: { ...baseOptions.value.legend, position: 'bottom' },
    plotOptions: { pie: { donut: { size: '68%' } } },
    // Axis config is meaningless on a donut and Apex warns about it.
    xaxis: undefined,
    yaxis: undefined,
    grid: undefined,
}));
</script>

<template>
    <component :is="chart" v-if="chart" type="donut" :height="height" :options="options" :series="values" />
    <div v-else :style="{ height: height + 'px' }" />
</template>
