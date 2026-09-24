<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Gauge, Globe, TrendingUp, Users, Wallet, Zap } from '@lucide/vue';
import TimeSeriesChart from '@/components/charts/TimeSeriesChart.vue';
import ErrorLogDialog from '@/components/admin/ErrorLogDialog.vue';
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
    searchTerms: { term: string; count: number }[];
    planMix: {
        total: number;
        paid: number;
        free: number;
        mrr: number;
        plans: { key: string; label: string; count: number; premium: boolean }[];
    };
    signupCountries: {
        known: { iso2: string; name: string; users: number }[];
        unknown: number;
    };
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

// Endpoints carry their own latency, so volume and speed are one row rather
// than two cards at opposite ends of the page — you can see at a glance whether
// the busiest endpoint is also the slowest.
const maxEndpoint = computed(() => Math.max(1, ...props.endpoints.map((e) => e.requests)));

/**
 * Paid plans should be visible in a list without reading the word. Free keeps
 * the neutral chip; pro and max each get their own tone, ordered by tier so the
 * more valuable plan reads as the more prominent one.
 */
const planTone = (plan: string) =>
    ({
        max: 'bg-violet-500/15 text-violet-700 ring-violet-500/30 dark:text-violet-300',
        pro: 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
    })[plan] ?? 'bg-muted text-muted-foreground ring-transparent';

const usd = (n: number) =>
    n.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

const maxCountry = computed(() => Math.max(1, ...props.signupCountries.known.map((c) => c.users)));

// A quota bar is only interesting as it approaches the ceiling.
const quotaTone = (percent: number) =>
    percent >= 95 ? 'bg-red-500' : percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500';

const maxTerm = computed(() => Math.max(1, ...props.searchTerms.map((t) => t.count)));
</script>

<template>
    <div class="space-y-4 p-4 sm:p-6">
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

        <!-- KPI strip: one container with divided cells rather than six cards,
             and the two daily charts folded in as sparklines. -->
        <div class="grid divide-y divide-border rounded-xl border border-border sm:grid-cols-2 sm:divide-x lg:grid-cols-3 xl:grid-cols-6 xl:divide-y-0">
            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <TrendingUp class="size-3.5" /> Requests
                </span>
                <div class="mt-0.5 text-xl font-semibold tabular-nums">{{ nf(totals.requests) }}</div>
                <TimeSeriesChart
                    v-if="hasTraffic"
                    class="-mb-1"
                    :series="[trafficSeries[0]]"
                    :categories="categories"
                    type="area"
                    sparkline
                    :height="34"
                />
            </div>

            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <AlertTriangle class="size-3.5" /> Errors
                </span>
                <ErrorLogDialog :range="range" :count="totals.errors">
                    <div class="mt-0.5 text-xl font-semibold tabular-nums">
                        {{ nf(totals.errors) }}
                        <span class="text-sm font-normal text-muted-foreground">/ {{ pct(totals.errors, totals.requests) }}</span>
                    </div>
                </ErrorLogDialog>
            </div>

            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Zap class="size-3.5" /> Throttled
                </span>
                <div class="mt-0.5 text-xl font-semibold tabular-nums">{{ nf(totals.throttled) }}</div>
                <p class="text-[11px] text-muted-foreground">429s — quota pressure</p>
            </div>

            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Gauge class="size-3.5" /> p95 latency
                </span>
                <div class="mt-0.5 text-xl font-semibold tabular-nums">{{ ms(latency.p95) }}</div>
                <p class="text-[11px] text-muted-foreground">p50 {{ ms(latency.p50) }} · max {{ ms(latency.max) }}</p>
            </div>

            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Users class="size-3.5" /> Active users
                </span>
                <div class="mt-0.5 text-xl font-semibold tabular-nums">{{ nf(totals.unique_users) }}</div>
                <TimeSeriesChart
                    v-if="hasTraffic"
                    class="-mb-1"
                    :series="usersSeries"
                    :categories="categories"
                    type="area"
                    sparkline
                    :height="34"
                />
            </div>

            <!-- Not range-scoped: the user base as it stands, whatever window is
                 selected. MRR is list price times headcount, not billed revenue. -->
            <div class="min-w-0 p-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Wallet class="size-3.5" /> Paid users
                </span>
                <div class="mt-0.5 text-xl font-semibold tabular-nums">
                    {{ nf(planMix.paid) }}<span class="text-sm font-normal text-muted-foreground">/{{ nf(planMix.total) }}</span>
                    <span class="ml-1 text-sm font-normal text-muted-foreground">· {{ usd(planMix.mrr) }} MRR</span>
                </div>
                <div class="mt-1.5 flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-muted">
                    <div
                        v-for="p in planMix.plans.filter((x) => x.count > 0)"
                        :key="p.key"
                        class="h-full"
                        :class="p.premium ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                        :style="{ width: pct(p.count, Math.max(1, planMix.total)) }"
                        :title="`${p.label}: ${p.count}`"
                    />
                </div>
            </div>
        </div>

        <p v-if="!hasTraffic" class="rounded-xl border border-border p-8 text-center text-sm text-muted-foreground">
            No API requests in this period.
        </p>

        <template v-else>
            <!-- Endpoints, with their own latency. Volume and speed were two
                 cards at opposite ends of the page; reading them together is the
                 whole question. -->
            <div class="rounded-xl border border-border">
                <div class="border-b border-border px-4 py-2.5">
                    <h2 class="text-sm font-medium">Endpoints</h2>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-[11px] text-muted-foreground">
                            <th class="px-4 py-1.5 text-left font-normal">Endpoint</th>
                            <th class="px-2 py-1.5 text-right font-normal">Requests</th>
                            <th class="px-2 py-1.5 text-right font-normal">Errors</th>
                            <th class="px-2 py-1.5 text-right font-normal">Avg</th>
                            <th class="px-4 py-1.5 text-right font-normal">Max</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="e in endpoints" :key="`${e.method} ${e.endpoint}`">
                            <td class="max-w-0 px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]">{{ e.method }}</span>
                                    <code class="min-w-0 flex-1 truncate font-mono text-xs">{{ e.endpoint }}</code>
                                </div>
                                <!-- The bar the old chart carried, inline. -->
                                <div class="mt-1 h-0.5 rounded-full bg-primary/60" :style="{ width: pct(e.requests, maxEndpoint) }" />
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ nf(e.requests) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums" :class="e.errors > 0 ? 'text-red-500' : 'text-muted-foreground'">
                                {{ nf(e.errors) }}
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums text-muted-foreground">{{ ms(e.avg_ms) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ ms(e.max_ms) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- top users -->
                <div class="rounded-xl border border-border">
                    <div class="border-b border-border px-4 py-2.5">
                        <h2 class="text-sm font-medium">Busiest users</h2>
                    </div>
                    <ul class="divide-y divide-border">
                        <li v-for="u in topUsers" :key="u.id" class="flex items-center gap-3 px-4 py-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span class="truncate text-sm">{{ u.name }}</span>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase ring-1"
                                        :class="planTone(u.plan)"
                                    >{{ u.plan }}</span>
                                </div>
                                <p class="truncate text-xs text-muted-foreground">{{ u.email }}</p>
                            </div>
                            <span class="shrink-0 text-sm tabular-nums">{{ nf(u.requests) }}</span>
                        </li>
                        <li v-if="!topUsers.length" class="px-4 py-6 text-center text-sm text-muted-foreground">
                            No users in this period.
                        </li>
                    </ul>
                </div>

                <!-- quota pressure: the upgrade-candidate list -->
                <div class="rounded-xl border border-border">
                    <div class="border-b border-border px-4 py-2.5">
                        <h2 class="text-sm font-medium">Quota used today</h2>
                    </div>
                    <ul class="divide-y divide-border">
                        <li v-for="u in quota" :key="u.id" class="px-4 py-2">
                            <div class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <span class="truncate text-sm">{{ u.name }}</span>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase ring-1"
                                            :class="planTone(u.plan)"
                                        >{{ u.plan }}</span>
                                    </div>
                                </div>
                                <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                    {{ nf(u.requests) }} / {{ nf(u.limit) }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full" :class="quotaTone(u.percent)" :style="{ width: `${Math.min(100, u.percent)}%` }" />
                            </div>
                        </li>
                        <li v-if="!quota.length" class="px-4 py-6 text-center text-sm text-muted-foreground">
                            Nobody has called the API today.
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- Outside the traffic guard on purpose: where users are is a fact about
             the user base, not about activity in the selected window. -->
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-border p-4">
                <h2 class="mb-3 text-sm font-medium">Top searches</h2>
                <ul class="space-y-1.5">
                    <li v-for="t in searchTerms" :key="t.term" class="flex items-center gap-3">
                        <span class="min-w-0 flex-1 truncate text-sm">{{ t.term }}</span>
                        <div class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary/60" :style="{ width: pct(t.count, maxTerm) }" />
                        </div>
                        <span class="w-8 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ nf(t.count) }}</span>
                    </li>
                    <li v-if="!searchTerms.length" class="py-4 text-center text-sm text-muted-foreground">
                        No searches in this period.
                    </li>
                </ul>
            </div>

            <div class="rounded-xl border border-border p-4">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-medium">
                    <Globe class="size-4 text-muted-foreground" /> Where users signed up
                </h2>
                <ul class="space-y-1.5">
                    <li v-for="c in signupCountries.known" :key="c.iso2" class="flex items-center gap-3">
                        <span class="min-w-0 flex-1 truncate text-sm">{{ c.name }}</span>
                        <div class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary/60" :style="{ width: pct(c.users, maxCountry) }" />
                        </div>
                        <span class="w-8 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ nf(c.users) }}</span>
                    </li>
                </ul>
                <!-- Says why it is empty rather than showing a blank panel: this
                     is only captured from now on, and cannot be backfilled. -->
                <p v-if="signupCountries.unknown" class="mt-3 text-xs text-muted-foreground">
                    {{ nf(signupCountries.unknown) }}
                    {{ signupCountries.unknown === 1 ? 'user has' : 'users have' }} no country —
                    it is recorded at registration, so accounts created earlier don't have one.
                </p>
            </div>
        </div>

        <!-- The two numbers come from different tables on purpose; say so before
             the first discrepancy reads as a bug. -->
        <p class="text-xs text-muted-foreground">
            Detail is kept for {{ retentionDays }} days and includes throttled requests.
            “Quota used today” comes from the daily billing counter, which excludes them —
            so the two will not always agree.
        </p>
    </div>
</template>
