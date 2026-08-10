<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, Info, XCircle } from '@lucide/vue';

interface Issue {
    level: 'error' | 'warning';
    scope: string;
    message: string;
}

const props = defineProps<{
    verification: { passed: boolean; issues: Issue[] } | null;
    unmapped: Array<{ label: string; detail: string }>;
}>();

const errors = computed(() => props.verification?.issues.filter((i) => i.level === 'error') ?? []);
const warnings = computed(() => props.verification?.issues.filter((i) => i.level === 'warning') ?? []);
</script>

<template>
    <section
        v-if="errors.length || warnings.length || unmapped.length"
        class="ds-card p-6"
    >
        <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Checks</h2>

        <!-- Recomputed from the card, not taken on the model's word. -->
        <div v-if="errors.length" class="mt-4 space-y-2">
            <div
                v-for="(issue, i) in errors"
                :key="`e${i}`"
                class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-fg"
            >
                <XCircle class="mt-0.5 size-4 shrink-0 text-destructive" />
                <span>{{ issue.message }}</span>
            </div>
            <p class="text-xs text-fg-subtle">
                These numbers don't add up. Check them against the card before accepting the sections they affect —
                a value that can't be stored is left out rather than saved wrong.
            </p>
        </div>

        <div v-if="warnings.length" class="mt-4 space-y-2">
            <div
                v-for="(issue, i) in warnings"
                :key="`w${i}`"
                class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-fg"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-400" />
                <span>{{ issue.message }}</span>
            </div>
        </div>

        <!-- Read from the card but with nowhere to live on a course. Kept on the
             scan so it's recoverable, and named here so it isn't a silent drop. -->
        <div v-if="unmapped.length" class="mt-5 border-t border-line pt-4">
            <h3 class="flex items-center gap-1.5 text-sm font-medium text-fg">
                <Info class="size-4 text-fg-subtle" /> Read, but not stored
            </h3>
            <dl class="mt-3 space-y-2">
                <div v-for="(item, i) in unmapped" :key="i" class="text-sm">
                    <dt class="text-fg-muted">{{ item.label }}</dt>
                    <dd class="text-fg-subtle">{{ item.detail }}</dd>
                </div>
            </dl>
        </div>
    </section>
</template>
