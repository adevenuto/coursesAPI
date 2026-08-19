import { computed, onMounted, shallowRef } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Shared ApexCharts setup.
 *
 * Two things every chart in this app has to get right, factored out so six
 * components don't each reimplement them:
 *
 * 1. The library is imported dynamically inside onMounted. The app renders
 *    server-side and ApexCharts touches `window` at module scope, so a static
 *    import breaks SSR. Callers render `<component :is="chart" v-if="chart">`
 *    with a fixed-height spacer for the v-else, which also stops the page
 *    jumping when it hydrates.
 *
 * 2. Theming derives from `resolvedAppearance`, which is a reactive ref. Reading
 *    document.documentElement.classList inside a computed — as the original
 *    UsageChart did — is untrackable by Vue, so charts kept the old palette
 *    after a theme switch until they happened to remount.
 *
 * ApexCharts can't read Tailwind classes, so colours are literal hex.
 */
export const chartPalette = {
    ok: '#22c55e',
    error: '#ef4444',
    throttled: '#f59e0b',
    categorical: ['#22c55e', '#6366f1', '#06b6d4', '#a855f7', '#ec4899', '#f59e0b'],
};

export function useChartTheme() {
    const chart = shallowRef<unknown>(null);

    onMounted(async () => {
        chart.value = (await import('vue3-apexcharts')).default;
    });

    const { resolvedAppearance } = useAppearance();
    const isDark = computed(() => resolvedAppearance.value === 'dark');

    const baseOptions = computed(() => ({
        chart: {
            toolbar: { show: false },
            background: 'transparent',
            fontFamily: 'inherit',
            animations: { enabled: false },
        },
        theme: { mode: isDark.value ? 'dark' : 'light' },
        dataLabels: { enabled: false },
        grid: {
            borderColor: isDark.value ? '#262626' : '#e5e7eb',
            strokeDashArray: 4,
        },
        xaxis: {
            labels: { rotate: 0, style: { colors: '#9ca3af' } },
            axisBorder: { show: false },
            axisTicks: { show: false },
            tooltip: { enabled: false },
        },
        yaxis: {
            labels: { style: { colors: '#9ca3af' } },
            min: 0,
            forceNiceScale: true,
        },
        tooltip: { theme: isDark.value ? 'dark' : 'light' },
        legend: { labels: { colors: '#9ca3af' } },
    }));

    return { chart, isDark, baseOptions, palette: chartPalette };
}
