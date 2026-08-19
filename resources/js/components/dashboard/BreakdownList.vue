<script setup lang="ts">
import { computed } from 'vue';
import { nf } from '@/lib/format';

export interface BreakdownRow {
    label: string;
    value: number;
    muted?: boolean;
    mono?: boolean;
}

const props = withDefaults(
    defineProps<{
        title: string;
        rows: BreakdownRow[];
        empty?: string;
        note?: string;
    }>(),
    { empty: 'Nothing recorded yet.' },
);

// Proportional to the largest row, not the total — with a long tail, sharing
// against the total leaves every bar but the first invisible.
const max = computed(() => Math.max(1, ...props.rows.map((r) => r.value)));
</script>

<template>
    <!-- CSS bars rather than a chart: this is the most-visited authenticated
         page, and a ranked list reads better than a donut for eight rows. -->
    <div class="rounded-xl border border-border p-5">
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h2 class="text-sm font-medium">{{ title }}</h2>
            <span v-if="note" class="text-xs text-muted-foreground">{{ note }}</span>
        </div>

        <p v-if="!rows.length" class="py-6 text-center text-sm text-muted-foreground">
            {{ empty }}
        </p>

        <ul v-else class="space-y-2.5">
            <li v-for="(row, i) in rows" :key="i">
                <div class="flex items-baseline justify-between gap-3 text-sm">
                    <span
                        class="min-w-0 truncate"
                        :class="[row.muted && 'text-muted-foreground', row.mono && 'font-mono text-xs']"
                        :title="row.label"
                    >{{ row.label }}</span>
                    <span class="shrink-0 tabular-nums text-muted-foreground">{{ nf(row.value) }}</span>
                </div>
                <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="row.muted ? 'bg-muted-foreground/40' : 'bg-emerald-500'"
                        :style="{ width: Math.max(2, (row.value / max) * 100) + '%' }"
                    />
                </div>
            </li>
        </ul>
    </div>
</template>
