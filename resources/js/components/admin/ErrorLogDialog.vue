<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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

interface StatusCount {
    status: number;
    count: number;
}

const props = withDefaults(
    defineProps<{
        range: string;
        count: number;
        /** Narrows to one row of the Endpoints table; absent means the whole range. */
        endpoint?: string;
        method?: string;
        /** `throttled` drills into 429s, which are excluded from every error figure. */
        mode?: 'errors' | 'throttled';
    }>(),
    { mode: 'errors' },
);

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const rows = ref<ErrorRow[]>([]);
const summary = ref<StatusCount[]>([]);
const total = ref(0);

type Sort = 'newest' | 'oldest';

/** '' is every code; otherwise the status as a string, since <option> values are. */
const code = ref('');
const sort = ref<Sort>('newest');

const isThrottled = computed(() => props.mode === 'throttled');
const scoped = computed(() => !!props.endpoint);

/** What each code means here, so the panel answers rather than just counts. */
const STATUS_LABELS: Record<number, string> = {
    400: 'Bad request',
    401: 'Unauthenticated',
    403: 'Premium plan required',
    404: 'Not found',
    422: 'Validation failed',
    429: 'Quota exceeded',
    500: 'Server error',
    503: 'Unavailable',
};

const label = (status: number) =>
    STATUS_LABELS[status] ?? (status >= 500 ? 'Server error' : 'Client error');

/** Widest bar is the dominant cause; the rest are relative to it. */
const widest = computed(() =>
    Math.max(1, ...summary.value.map((s) => s.count)),
);

/** True once the server has more than it handed back, so the list can say so. */
const truncated = computed(() => total.value > rows.value.length);

/**
 * Sorted here, but no longer filtered here.
 *
 * Filtering used to happen in memory — the rows were in hand and capped at fifty,
 * so a round trip was pure latency. That stopped being true once a server-counted
 * summary sits directly above them: narrowing 592 errors against the fifty we
 * happen to hold would contradict the number printed right above the list. The
 * code filter now re-queries; only the ordering, which can't misrepresent a
 * count, stays local.
 */
const visible = computed<ErrorRow[]>(() =>
    [...rows.value].sort((a, b) =>
        sort.value === 'newest'
            ? b.at.localeCompare(a.at)
            : a.at.localeCompare(b.at),
    ),
);

async function load() {
    loading.value = true;
    failed.value = false;

    // URLSearchParams rather than concatenation: endpoints carry / and {} .
    const params = new URLSearchParams({ range: props.range });

    if (props.endpoint) {
        params.set('endpoint', props.endpoint);
    }

    if (props.method) {
        params.set('method', props.method);
    }

    if (props.mode === 'throttled') {
        params.set('mode', 'throttled');
    }

    if (code.value !== '') {
        params.set('status', code.value);
    }

    try {
        const response = await fetch(
            `/admin/analytics/errors?${params.toString()}`,
            {
                headers: { Accept: 'application/json' },
            },
        );

        // fetch only rejects on a network error, so a 419 or a 500 would
        // otherwise sail through and render as an empty log.
        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const body = await response.json();

        rows.value = body.errors ?? [];
        // The summary covers the whole scope regardless of the code filter, so
        // narrowing never hides the other causes from view.
        summary.value = body.summary ?? [];
        total.value = body.total ?? 0;
    } catch {
        failed.value = true;
        rows.value = [];
        summary.value = [];
        total.value = 0;
    } finally {
        loading.value = false;
    }
}

// The filter is a server query now; re-run it rather than narrowing what's held.
watch(code, () => {
    if (open.value) {
        load();
    }
});

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
                <DialogTitle>{{
                    isThrottled ? 'Throttled requests' : 'Failed requests'
                }}</DialogTitle>
                <DialogDescription>
                    <!-- Scoped or not, the description has to say which, or the
                         dialog misrepresents what it is showing. -->
                    <template v-if="scoped">
                        <code class="font-mono text-xs"
                            >{{ method }} {{ endpoint }}</code
                        >
                        —
                        {{ nf(count) }}
                        {{ isThrottled ? 'throttled request' : 'error'
                        }}{{ count === 1 ? '' : 's' }} in the last {{ range }}.
                    </template>
                    <template v-else>
                        The {{ nf(count) }}
                        {{ isThrottled ? 'throttled request' : 'error'
                        }}{{ count === 1 ? '' : 's' }} across all endpoints in
                        the last {{ range }}.
                    </template>
                    <template v-if="isThrottled">
                        These are quota rejections, not faults — they're counted
                        apart from errors.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" /> Loading…
            </div>

            <p
                v-else-if="failed"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                Couldn't load the log.
            </p>

            <p
                v-else-if="!rows.length"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                No {{ isThrottled ? 'throttled' : 'failed' }} requests in this
                period.
            </p>

            <template v-else>
                <!--
                    What the number on the table actually consists of. Counted on
                    the server over the whole scope, so it stays true even though
                    the list below it is only the newest page.
                -->
                <div
                    v-if="summary.length > 1"
                    class="space-y-1.5 border-b border-border pb-3"
                >
                    <div
                        v-for="s in summary"
                        :key="s.status"
                        class="flex items-center gap-2 text-xs"
                    >
                        <span
                            class="w-10 shrink-0 rounded px-1.5 py-0.5 text-center text-[11px] font-medium tabular-nums ring-1"
                            :class="statusTone(s.status)"
                            >{{ s.status }}</span
                        >
                        <span
                            class="w-40 shrink-0 truncate text-muted-foreground"
                            >{{ label(s.status) }}</span
                        >
                        <span class="w-12 shrink-0 text-right tabular-nums">{{
                            nf(s.count)
                        }}</span>
                        <span
                            class="h-1.5 min-w-0 flex-1 rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full"
                                :class="
                                    s.status >= 500
                                        ? 'bg-red-500/70'
                                        : 'bg-amber-500/70'
                                "
                                :style="{
                                    width: `${Math.round((s.count / widest) * 100)}%`,
                                }"
                            />
                        </span>
                    </div>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2"
                >
                    <!-- Options come from the server summary, not the rows: the
                         rows are capped, so their codes would under-report and
                         their counts would be wrong. A native select on purpose:
                         the Select primitive portals, and this lives in a dialog. -->
                    <select
                        v-model="code"
                        class="cursor-pointer rounded-lg border border-border bg-transparent px-2 py-1 text-xs font-medium focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Filter by status code"
                    >
                        <option value="">All codes ({{ nf(total) }})</option>
                        <option
                            v-for="s in summary"
                            :key="s.status"
                            :value="String(s.status)"
                        >
                            {{ s.status }} ({{ nf(s.count) }})
                        </option>
                    </select>

                    <div class="flex rounded-lg border border-border p-0.5">
                        <button
                            v-for="option in ['newest', 'oldest'] as Sort[]"
                            :key="option"
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 text-xs font-medium capitalize transition"
                            :class="
                                sort === option
                                    ? 'bg-muted text-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="sort = option"
                        >
                            {{ option }}
                        </button>
                    </div>
                </div>

                <ul class="divide-y divide-border text-sm">
                    <li v-for="row in visible" :key="row.id" class="py-2.5">
                        <div class="flex items-start gap-2">
                            <span
                                class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium tabular-nums ring-1"
                                :class="statusTone(row.status)"
                                >{{ row.status }}</span
                            >
                            <span
                                class="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]"
                                >{{ row.method }}</span
                            >
                            <code
                                class="min-w-0 flex-1 truncate font-mono text-xs"
                                >{{ row.endpoint }}</code
                            >
                            <span
                                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >
                                {{
                                    row.duration_ms === null
                                        ? '—'
                                        : ms(row.duration_ms)
                                }}
                            </span>
                        </div>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-2 pl-1 text-xs text-muted-foreground"
                        >
                            <span class="tabular-nums">{{ at(row.at) }}</span>
                            <span>·</span>
                            <!-- A deleted user leaves its requests behind. -->
                            <span class="truncate">{{
                                row.email ?? 'deleted user'
                            }}</span>
                            <code
                                v-if="row.query"
                                class="min-w-0 truncate font-mono"
                                >{{ row.query }}</code
                            >
                        </div>
                    </li>
                </ul>

                <p
                    v-if="truncated"
                    class="pt-2 text-center text-xs text-muted-foreground"
                >
                    Showing the {{ nf(rows.length) }} most recent of
                    {{ nf(total) }}.
                </p>
            </template>
        </DialogScrollContent>
    </Dialog>
</template>
