<script setup lang="ts">
import { computed } from 'vue';
import { useChartTheme } from './useChartTheme';

const props = withDefaults(
    defineProps<{
        labels: string[];
        values: number[];
        name?: string;
        horizontal?: boolean;
        height?: number;
    }>(),
    { name: 'Requests', horizontal: true, height: 260 },
);

const { chart, baseOptions, palette } = useChartTheme();

const series = computed(() => [{ name: props.name, data: props.values }]);

const options = computed(() => ({
    ...baseOptions.value,
    chart: { ...baseOptions.value.chart, type: 'bar' },
    colors: [palette.ok],
    plotOptions: {
        bar: { horizontal: props.horizontal, borderRadius: 3, barHeight: '65%' },
    },
    stroke: { width: 0 },
    xaxis: { ...baseOptions.value.xaxis, categories: props.labels },
    // Endpoint paths are long; give them room and don't truncate mid-segment.
    yaxis: {
        ...baseOptions.value.yaxis,
        labels: { style: { colors: '#9ca3af', fontSize: '11px' }, maxWidth: 260 },
    },
    legend: { show: false },
}));
</script>

<template>
    <component :is="chart" v-if="chart" type="bar" :height="height" :options="options" :series="series" />
    <div v-else :style="{ height: height + 'px' }" />
</template>
