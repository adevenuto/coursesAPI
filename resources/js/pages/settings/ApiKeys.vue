<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, Crown, KeyRound, Plus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface TokenRow {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
}

const props = defineProps<{
    plan: { key: string; label: string; per_day: number; per_minute: number; premium: boolean };
    usage: { today: number; limit: number; series: { date: string; requests: number }[] };
    tokens: TokenRow[];
    newToken: string | null;
    maxKeys: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API keys', href: '/settings/api-keys' }],
    },
});

const form = useForm({ name: '' });
const page = usePage();

const submit = () =>
    form.post('/settings/api-keys', {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });

const revoke = (id: number) => {
    if (confirm('Revoke this API key? Any app using it will stop working.')) {
        router.delete(`/settings/api-keys/${id}`, { preserveScroll: true });
    }
};

// One-time plaintext token copy.
const { copy, copied } = useClipboard({ source: () => props.newToken ?? '' });

// Usage progress.
const usedPct = computed(() =>
    props.usage.limit > 0
        ? Math.min(100, Math.round((props.usage.today / props.usage.limit) * 100))
        : 0,
);
const nf = (n: number) => n.toLocaleString('en-US');

// ApexCharts (client-only for SSR safety).
const chart = shallowRef<unknown>(null);
onMounted(async () => {
    chart.value = (await import('vue3-apexcharts')).default;
});

const isDark = () =>
    typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

const series = computed(() => [
    { name: 'Requests', data: props.usage.series.map((d) => d.requests) },
]);

const chartOptions = computed(() => ({
    chart: { type: 'area', height: 220, toolbar: { show: false }, background: 'transparent', fontFamily: 'inherit' },
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
        categories: props.usage.series.map((d) =>
            new Date(d.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'numeric', day: 'numeric' }),
        ),
        labels: { rotate: 0, style: { colors: '#9ca3af' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
        tooltip: { enabled: false },
    },
    yaxis: { labels: { style: { colors: '#9ca3af' } }, min: 0, forceNiceScale: true },
    tooltip: { theme: isDark() ? 'dark' : 'light' },
}));

const errors = computed(() => ({ ...form.errors, ...(page.props.errors as Record<string, string>) }));
</script>

<template>
    <Head title="API keys" />

    <div class="flex flex-col space-y-8">
        <Heading
            variant="small"
            title="API keys"
            description="Create keys, track usage, and manage your plan."
        />

        <!-- Plan + usage -->
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-border p-5">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">Current plan</span>
                    <span
                        v-if="plan.premium"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        <Crown class="size-3" /> Premium
                    </span>
                </div>
                <div class="mt-1 text-2xl font-semibold">{{ plan.label }}</div>
                <dl class="mt-4 space-y-1 text-sm text-muted-foreground">
                    <div class="flex justify-between">
                        <dt>Daily requests</dt>
                        <dd class="font-medium text-foreground">{{ nf(plan.per_day) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Burst / minute</dt>
                        <dd class="font-medium text-foreground">{{ nf(plan.per_minute) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Green-center GPS</dt>
                        <dd class="font-medium text-foreground">{{ plan.premium ? 'Included' : '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-border p-5">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted-foreground">Usage today</span>
                    <span class="font-medium">{{ nf(usage.today) }} / {{ nf(usage.limit) }}</span>
                </div>
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: usedPct + '%' }" />
                </div>
                <div class="mt-4 -mx-1">
                    <component :is="chart" v-if="chart" type="area" height="200" :options="chartOptions" :series="series" />
                    <div v-else class="h-[200px]" />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">Requests over the last 14 days</p>
            </div>
        </div>

        <!-- One-time token reveal -->
        <div
            v-if="newToken"
            class="rounded-xl border border-emerald-500/40 bg-emerald-500/5 p-5"
        >
            <div class="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                <KeyRound class="size-4" /> Your new API key
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                Copy it now — for your security it won't be shown again.
            </p>
            <div class="mt-3 flex items-center gap-2">
                <code class="flex-1 overflow-x-auto rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm">{{ newToken }}</code>
                <Button type="button" variant="secondary" size="sm" @click="copy()">
                    <component :is="copied ? Check : Copy" class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <!-- Create key -->
        <form class="flex items-end gap-3" @submit.prevent="submit">
            <div class="flex-1">
                <Label for="key-name">New key name</Label>
                <Input
                    id="key-name"
                    v-model="form.name"
                    class="mt-1"
                    placeholder="e.g. Production, Staging, Mobile app"
                    maxlength="50"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>
            <Button type="submit" :disabled="form.processing">
                <Plus class="size-4" /> Create key
            </Button>
        </form>

        <!-- Key list -->
        <div class="rounded-xl border border-border">
            <div class="flex items-center justify-between border-b border-border px-5 py-3">
                <h3 class="text-sm font-medium">Your keys</h3>
                <span class="text-xs text-muted-foreground">{{ tokens.length }} / {{ maxKeys }}</span>
            </div>
            <ul v-if="tokens.length" class="divide-y divide-border">
                <li v-for="t in tokens" :key="t.id" class="flex items-center gap-4 px-5 py-3.5">
                    <KeyRound class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ t.name }}</div>
                        <div class="text-xs text-muted-foreground">
                            Created {{ t.created_at }} · Last used {{ t.last_used_at ?? 'never' }}
                        </div>
                    </div>
                    <Button type="button" variant="ghost" size="sm" class="text-destructive" @click="revoke(t.id)">
                        <Trash2 class="size-4" /> Revoke
                    </Button>
                </li>
            </ul>
            <p v-else class="px-5 py-8 text-center text-sm text-muted-foreground">
                No API keys yet. Create one above to start calling the API.
            </p>
        </div>
    </div>
</template>
