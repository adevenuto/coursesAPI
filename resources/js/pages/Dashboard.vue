<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useClipboard } from '@vueuse/core';
import {
    ArrowUpRight,
    BookOpen,
    Check,
    Copy,
    Crown,
    KeyRound,
    Plus,
    Zap,
} from '@lucide/vue';
import UsageChart from '@/components/dashboard/UsageChart.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { nf } from '@/lib/format';

const props = defineProps<{
    baseUrl: string;
    plan: { key: string; label: string; per_day: number; per_minute: number; premium: boolean };
    usage: { today: number; limit: number; series: { date: string; requests: number }[] };
    keys: { count: number; recent: { name: string; last_used_at: string | null } | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const page = usePage();
const userName = computed(() => page.props.auth.user?.name?.split(' ')[0] ?? 'there');

const usedPct = computed(() =>
    props.usage.limit > 0 ? Math.min(100, Math.round((props.usage.today / props.usage.limit) * 100)) : 0,
);

const snippet = `curl "${props.baseUrl}/api/v1/courses?q=pebble" \\
  -H "Authorization: Bearer YOUR_API_KEY"`;
const { copy, copied } = useClipboard({ source: () => snippet });
</script>

<template>
    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Welcome back, {{ userName }}.</h1>
            <p class="text-sm text-muted-foreground">Here's your API activity at a glance.</p>
        </div>

        <!-- stat tiles -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-border p-5">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">Plan</span>
                    <span
                        v-if="plan.premium"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                    ><Crown class="size-3" /> Premium</span>
                </div>
                <div class="mt-1 text-2xl font-semibold">{{ plan.label }}</div>
                <div class="mt-1 text-xs text-muted-foreground">{{ nf(plan.per_day) }} req/day · {{ nf(plan.per_minute) }}/min</div>
            </div>

            <div class="rounded-xl border border-border p-5">
                <span class="text-sm text-muted-foreground">Usage today</span>
                <div class="mt-1 text-2xl font-semibold">{{ nf(usage.today) }} <span class="text-base font-normal text-muted-foreground">/ {{ nf(usage.limit) }}</span></div>
                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: usedPct + '%' }" />
                </div>
            </div>

            <div class="rounded-xl border border-border p-5">
                <span class="text-sm text-muted-foreground">API keys</span>
                <div class="mt-1 text-2xl font-semibold">{{ keys.count }}</div>
                <Link href="/settings/api-keys" class="mt-1 inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline dark:text-emerald-400">
                    Manage keys <ArrowUpRight class="size-3" />
                </Link>
            </div>
        </div>

        <!-- usage chart -->
        <div class="rounded-xl border border-border p-5">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-medium">Requests · last 14 days</h2>
                <span class="text-xs text-muted-foreground">{{ plan.label }} plan</span>
            </div>
            <UsageChart :series="usage.series" :height="240" />
        </div>

        <!-- quick start + CTAs -->
        <div class="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <!-- min-w-0: a grid item defaults to min-width:auto, so without this
                 the long snippet sets the column's minimum width and the card
                 pushes the page wide instead of the <pre> scrolling. -->
            <div class="min-w-0 rounded-xl border border-border p-5">
                <div class="flex items-center gap-2 text-sm font-medium"><Zap class="size-4 text-emerald-500" /> Quick start</div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ keys.count === 0 ? 'Create a key, then make your first call:' : 'Make a request with your key:' }}
                </p>
                <div class="relative mt-3 min-w-0">
                    <pre class="min-w-0 overflow-x-auto rounded-lg border border-border bg-muted/40 p-3 pr-20 font-mono text-xs leading-relaxed"><code>{{ snippet }}</code></pre>
                    <Button type="button" variant="secondary" size="sm" class="absolute top-2 right-2" @click="copy()">
                        <component :is="copied ? Check : Copy" class="size-3.5" /> {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </div>

            <div class="flex flex-col gap-3 rounded-xl border border-border p-5">
                <h2 class="text-sm font-medium">Next steps</h2>
                <Button as-child variant="default" class="justify-start">
                    <Link href="/settings/api-keys"><Plus class="size-4" /> {{ keys.count === 0 ? 'Create your first key' : 'Create a key' }}</Link>
                </Button>
                <Button as-child variant="outline" class="justify-start">
                    <Link href="/settings/billing"><Crown class="size-4 fill-[#f5b301] stroke-0" /> {{ plan.premium ? 'Manage plan' : 'Upgrade plan' }}</Link>
                </Button>
                <Button as-child variant="outline" class="justify-start">
                    <a href="/docs"><BookOpen class="size-4" /> Read the docs</a>
                </Button>
                <p v-if="keys.recent" class="mt-1 text-xs text-muted-foreground">
                    <KeyRound class="mr-1 inline size-3" />Most recent: <span class="font-medium text-foreground">{{ keys.recent.name }}</span> · last used {{ keys.recent.last_used_at ?? 'never' }}
                </p>
            </div>
        </div>
    </div>
</template>
