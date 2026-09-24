<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Loader2 } from '@lucide/vue';
import { ms, nf } from '@/lib/format';

/**
 * The failed requests behind the Errors figure.
 *
 * Rows are fetched when the dialog opens, not with the page: most loads never
 * open it, and fifty log lines are the widest thing on the page. Fetched once
 * per open so a stale list can't outlive a range change.
 */
interface ErrorRow {
    id: number;
    at: string;
    method: string;
    endpoint: string;
    status: number;
    duration_ms: number | null;
    user: string | null;
    email: string | null;
    query: string | null;
}

const props = defineProps<{ range: string; count: number }>();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const rows = ref<ErrorRow[]>([]);

type Sort = 'newest' | 'oldest';

/** '' is every code; otherwise the status as a string, since <option> values are. */
const code = ref('');
const sort = ref<Sort>('newest');

/**
 * Only the codes actually present, highest first, each with its count. Building
 * the list from the rows means the filter can never offer a code that would
 * return nothing.
 */
const codes = computed(() => {
    const counts = new Map<number, number>();
    for (const row of rows.value) counts.set(row.status, (counts.get(row.status) ?? 0) + 1);

    return [...counts.entries()]
        .sort((a, b) => b[0] - a[0])
        .map(([status, count]) => ({ status, count }));
});

/**
 * Filtered and ordered here rather than on the server: the rows are already in
 * hand and capped at fifty, so a round trip to narrow them would be pure latency.
 */
const visible = computed<ErrorRow[]>(() => {
    const wanted = code.value === '' ? null : Number(code.value);

    return rows.value
        .filter((row) => wanted === null || row.status === wanted)
        // Copied by filter() already, so sorting in place is safe here — but the
        // comparison stays on the ISO string, which sorts correctly as text.
        .sort((a, b) => (sort.value === 'newest' ? b.at.localeCompare(a.at) : a.at.localeCompare(b.at)));
});

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`/admin/analytics/errors?range=${encodeURIComponent(props.range)}`, {
            headers: { Accept: 'application/json' },
        });
        // fetch only rejects on a network error, so a 419 or a 500 would
        // otherwise sail through and render as an empty log.
        if (!response.ok) throw new Error(String(response.status));
        rows.value = (await response.json()).errors ?? [];
    } catch {
        failed.value = true;
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

function onOpenChange(next: boolean) {
    open.value = next;

    if (next) {
        // Reset both, so the dialog never opens showing a view narrowed an hour
        // ago — and never filters on a code the new range may not contain.
        code.value = '';
        sort.value = 'newest';
        load();
    }
}

// 5xx is ours, 4xx is theirs — worth telling apart at a glance.
const statusTone = (status: number) =>
    status >= 500
        ? 'bg-red-500/15 text-red-600 ring-red-500/30 dark:text-red-400'
        : 'bg-amber-500/15 text-amber-600 ring-amber-500/30 dark:text-amber-400';

const at = (value: string) => value.replace('T', ' ').replace(/\.\d+Z?$/, '');
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger as-child>
            <button
                type="button"
                class="cursor-pointer rounded text-left underline-offset-4 transition hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-default disabled:no-underline"
                :disabled="count === 0"
            >
                <slot />
            </button>
        </DialogTrigger>

        <DialogScrollContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Failed requests</DialogTitle>
                <DialogDescription>
                    The {{ nf(count) }} error{{ count === 1 ? '' : 's' }} in the last {{ range }}, newest first.
                    Throttled requests are counted separately and aren't shown here.
                </DialogDescription>
            </DialogHeader>

            <div v-if="loading" class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                <Loader2 class="size-4 animate-spin" /> Loading…
            </div>

            <p v-else-if="failed" class="py-10 text-center text-sm text-muted-foreground">
                Couldn't load the log.
            </p>

            <p v-else-if="!rows.length" class="py-10 text-center text-sm text-muted-foreground">
                No failed requests in this period.
            </p>

            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2">
                    <!-- Options come from the rows, so every code listed has
                         something behind it. A native select on purpose: the
                         Select primitive portals, and this lives in a dialog. -->
                    <select
                        v-model="code"
                        class="cursor-pointer rounded-lg border border-border bg-transparent px-2 py-1 text-xs font-medium focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Filter by status code"
                    >
                        <option value="">All codes ({{ nf(rows.length) }})</option>
                        <option v-for="c in codes" :key="c.status" :value="String(c.status)">
                            {{ c.status }} ({{ nf(c.count) }})
                        </option>
                    </select>

                    <div class="flex rounded-lg border border-border p-0.5">
                        <button
                            v-for="option in (['newest', 'oldest'] as Sort[])"
                            :key="option"
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 text-xs font-medium capitalize transition"
                            :class="sort === option ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            @click="sort = option"
                        >{{ option }}</button>
                    </div>
                </div>

                <ul class="divide-y divide-border text-sm">
                    <li v-for="row in visible" :key="row.id" class="py-2.5">
                        <div class="flex items-start gap-2">
                            <span
                                class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 tabular-nums"
                                :class="statusTone(row.status)"
                            >{{ row.status }}</span>
                            <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]">{{ row.method }}</span>
                            <code class="min-w-0 flex-1 truncate font-mono text-xs">{{ row.endpoint }}</code>
                            <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                {{ row.duration_ms === null ? '—' : ms(row.duration_ms) }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 pl-1 text-xs text-muted-foreground">
                            <span class="tabular-nums">{{ at(row.at) }}</span>
                            <span>·</span>
                            <!-- A deleted user leaves its requests behind. -->
                            <span class="truncate">{{ row.email ?? 'deleted user' }}</span>
                            <code v-if="row.query" class="min-w-0 truncate font-mono">{{ row.query }}</code>
                        </div>
                    </li>
                </ul>
            </template>
        </DialogScrollContent>
    </Dialog>
</template>
