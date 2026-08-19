<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Gauge, TrendingUp, Users, Zap } from '@lucide/vue';
import CategoryBarChart from '@/components/charts/CategoryBarChart.vue';
import DonutChart from '@/components/charts/DonutChart.vue';
import TimeSeriesChart from '@/components/charts/TimeSeriesChart.vue';
import { chartPalette } from '@/components/charts/useChartTheme';
import { ms, nf, pct, shortDate } from '@/lib/format';

interface Totals {
    requests: number;
    errors: number;
    throttled: number;
    unique_ips: number;
    unique_users: number;
    avg_ms: number;
}

const props = defineProps<{
    range: string;
    ranges: string[];
    totals: Totals;
    latency: { avg: number; p50: number; p95: number; max: number };
    traffic: { date: string; requests: number; errors: number; throttled: number }[];
    activeUsers: { date: string; users: number }[];
    endpoints: {
        endpoint: string;
        method: string;
        requests: number;
        avg_ms: number;
        max_ms: number;
        errors: number;
        throttled: number;
    }[];
    statuses: { label: string; count: number }[];
    clients: { label: string; count: number }[];
    searchTerms: { term: string; count: number }[];
    topUsers: {
        id: number;
        name: string;
        email: string;
        plan: string;
        requests: number;
        throttled: number;
        last_seen: string;
    }[];
    quota: {
        id: number;
        name: string;
        email: string;
        plan: string;
        requests: number;
        limit: number;
        percent: number;
    }[];
    retentionDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API analytics', href: '/admin/analytics' }],
    },
});

const rangeLabels: Record<string, string> = {
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
};

function setRange(range: string) {
    router.get('/admin/analytics', { range }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const hasTraffic = computed(() => props.totals.requests > 0);
const categories = computed(() => props.traffic.map((d) => shortDate(d.date)));

const trafficSeries = computed(() => [
    { name: 'OK', color: chartPalette.ok, data: props.traffic.map((d) => d.requests - d.errors - d.throttled) },
    { name: 'Errors', color: chartPalette.error, data: props.traffic.map((d) => d.errors) },
    { name: 'Throttled', color: chartPalette.throttled, data: props.traffic.map((d) => d.throttled) },
]);

const usersSeries = computed(() => [
    { name: 'Active users', color: chartPalette.categorical[1], data: props.activeUsers.map((d) => d.users) },
]);

const statusColors = computed(() =>
    props.statuses.map((s) =>
        s.label === 'Success' ? chartPalette.ok
        : s.label === 'Throttled' ? chartPalette.throttled
        : chartPalette.error,
    ),
);

// A quota bar is only interesting as it approaches the ceiling.
const quotaTone = (percent: number) =>
    percent >= 95 ? 'bg-red-500' : percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500';

const maxTerm = computed(() => Math.max(1, ...props.searchTerms.map((t) => t.count)));
</script>

<template>
    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- header + range -->
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">API analytics</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    How the API is being used, over the last {{ rangeLabels[range] ?? range }}.
                </p>
            </div>
            <div class="flex rounded-lg border border-border p-0.5">
                <button
                    v-for="r in ranges"
                    :key="r"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                    :class="r === range ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="setRange(r)"
                >{{ r }}</button>
            </div>
        </div>

        <!-- KPIs -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-border p-5">
                <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <TrendingUp class="size-4" /> Requests
                </span>
                <div class="mt-1 text-2xl font-semibold">{{ nf(totals.requests) }}</div>
            </div>
            <div class="rounded-xl border border-border p-5">
                <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <AlertTriangle class="size-4" /> Errors
                </span>
                <div class="mt-1 text-2xl font-semibold">
                    {{ nf(totals.errors) }}
                    <span class="text-base font-normal text-muted-foreground">
                        / {{ pct(totals.errors, totals.requests) }}
                    </span>
                </div>
            </div>
            <div class="rounded-xl border border-border p-5">
                <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Zap class="size-4" /> Throttled
                </span>
                <div class="mt-1 text-2xl font-semibold">{{ nf(totals.throttled) }}</div>
                <p class="mt-0.5 text-xs text-muted-foreground">429s — quota pressure</p>
            </div>
            <div class="rounded-xl border border-border p-5">
                <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Gauge class="size-4" /> p95 latency
                </span>
                <div class="mt-1 text-2xl font-semibold">{{ ms(latency.p95) }}</div>
                <p class="mt-0.5 text-xs text-muted-foreground">p50 {{ ms(latency.p50) }} · max {{ ms(latency.max) }}</p>
            </div>
            <div class="rounded-xl border border-border p-5">
                <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Users class="size-4" /> Active users
                </span>
                <div class="mt-1 text-2xl font-semibold">{{ nf(totals.unique_users) }}</div>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ nf(totals.unique_ips) }} distinct networks</p>
            </div>
        </div>

        <p v-if="!hasTraffic" class="rounded-xl border border-border p-8 text-center text-sm text-muted-foreground">
            No API requests in this period.
        </p>

        <template v-else>
            <!-- traffic -->
            <div class="rounded-xl border border-border p-5">
                <h2 class="mb-2 text-sm font-medium">Requests over time</h2>
                <TimeSeriesChart :series="trafficSeries" :categories="categories" type="bar" stacked :height="260" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- endpoints -->
                <div class="rounded-xl border border-border p-5">
                    <h2 class="mb-2 text-sm font-medium">Top endpoints</h2>
                    <CategoryBarChart
                        :labels="endpoints.map((e) => e.endpoint)"
                        :values="endpoints.map((e) => e.requests)"
                        :height="280"
                    />
                </div>

                <!-- statuses -->
                <div class="rounded-xl border border-border p-5">
                    <h2 class="mb-2 text-sm font-medium">Response mix</h2>
                    <DonutChart
                        :labels="statuses.map((s) => s.label)"
                        :values="statuses.map((s) => s.count)"
                        :colors="statusColors"
                        :height="280"
                    />
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- top users -->
                <div class="rounded-xl border border-border">
                    <div class="border-b border-border px-5 py-3">
                        <h2 class="text-sm font-medium">Busiest users</h2>
                    </div>
                    <ul class="divide-y divide-border">
                        <li v-for="u in topUsers" :key="u.id" class="flex items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-medium">{{ u.name }}</span>
                                    <span class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium uppercase">
                                        {{ u.plan }}
                                    </span>
                                </div>
                                <div class="truncate text-xs text-muted-foreground">
                                    {{ u.email }} · last seen {{ u.last_seen }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-medium tabular-nums">{{ nf(u.requests) }}</div>
                                <div v-if="u.throttled" class="text-xs text-amber-500">
                                    {{ nf(u.throttled) }} throttled
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- quota pressure: the upgrade-candidate list -->
                <div class="rounded-xl border border-border">
                    <div class="flex items-baseline justify-between gap-3 border-b border-border px-5 py-3">
                        <h2 class="text-sm font-medium">Quota used today</h2>
                        <span class="text-xs text-muted-foreground">from the billing counter</span>
                    </div>
                    <ul v-if="quota.length" class="divide-y divide-border">
                        <li v-for="q in quota" :key="q.id" class="px-5 py-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-sm">{{ q.name }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                    {{ nf(q.requests) }} / {{ nf(q.limit) }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="quotaTone(q.percent)"
                                    :style="{ width: Math.max(2, q.percent) + '%' }"
                                />
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-8 text-center text-sm text-muted-foreground">
                        Nobody has called the API today.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- search terms: what people are looking for -->
                <div class="rounded-xl border border-border p-5">
                    <h2 class="mb-3 text-sm font-medium">Top searches</h2>
                    <ul v-if="searchTerms.length" class="space-y-2.5">
                        <li v-for="t in searchTerms" :key="t.term">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate">{{ t.term }}</span>
                                <span class="shrink-0 tabular-nums text-muted-foreground">{{ nf(t.count) }}</span>
                            </div>
                            <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-emerald-500"
                                    :style="{ width: Math.max(2, (t.count / maxTerm) * 100) + '%' }"
                                />
                            </div>
                        </li>
                    </ul>
                    <p v-else class="py-8 text-center text-sm text-muted-foreground">No searches recorded.</p>
                </div>

                <!-- clients -->
                <div class="rounded-xl border border-border p-5">
                    <h2 class="mb-2 text-sm font-medium">Clients</h2>
                    <DonutChart
                        v-if="clients.length"
                        :labels="clients.map((c) => c.label)"
                        :values="clients.map((c) => c.count)"
                        :height="260"
                    />
                    <p v-else class="py-8 text-center text-sm text-muted-foreground">No client data.</p>
                </div>
            </div>

            <!-- active users + latency detail -->
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-border p-5">
                    <h2 class="mb-2 text-sm font-medium">Active users per day</h2>
                    <TimeSeriesChart
                        :series="usersSeries"
                        :categories="activeUsers.map((d) => shortDate(d.date))"
                        type="line"
                        :height="220"
                    />
                </div>

                <div class="rounded-xl border border-border">
                    <div class="border-b border-border px-5 py-3">
                        <h2 class="text-sm font-medium">Latency by endpoint</h2>
                    </div>
                    <ul class="divide-y divide-border">
                        <li v-for="e in endpoints" :key="e.endpoint" class="flex items-center gap-3 px-5 py-2.5">
                            <code class="min-w-0 flex-1 truncate font-mono text-xs">{{ e.endpoint }}</code>
                            <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                avg {{ ms(e.avg_ms) }} · max {{ ms(e.max_ms) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- The two numbers come from different tables on purpose; say so before
             the first discrepancy reads as a bug. -->
        <p class="text-xs text-muted-foreground">
            Detail is kept for {{ retentionDays }} days and includes throttled requests.
            “Quota used today” comes from the daily billing counter, which excludes them —
            so the two will not always agree.
        </p>
    </div>
</template>
