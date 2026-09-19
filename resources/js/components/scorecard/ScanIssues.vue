<script setup lang="ts">
import { computed, ref } from 'vue';
import { AlertTriangle, ChevronDown, Info, ScrollText, XCircle } from '@lucide/vue';

interface Issue {
    level: 'error' | 'warning';
    scope: string;
    message: string;
    /** Present only on range failures — the single value this addresses. */
    override?: string;
}

const props = defineProps<{
    verification: { passed: boolean; issues: Issue[] } | null;
    unmapped: Array<{ label: string; detail: string }>;
    notes?: string | null;
}>();

/**
 * Range failures the editor has approved.
 *
 * Owned by the page rather than here, because the apply request is posted from
 * ScanDiff and these have to travel with it.
 */
const approved = defineModel<string[]>('approved', { default: () => [] });

function approve(key: string, on: boolean) {
    approved.value = on
        ? [...new Set([...approved.value, key])]
        : approved.value.filter((k) => k !== key);
}

const errors = computed(() => props.verification?.issues.filter((i) => i.level === 'error') ?? []);
const warnings = computed(() => props.verification?.issues.filter((i) => i.level === 'warning') ?? []);

// Collapsed by default: the notes run to a couple of thousand characters on a
// card with anything to say, and left open they push the diff — the thing the
// editor actually came to review — off the screen. The first line stays visible
// so there's something to judge whether it's worth opening.
const notesOpen = ref(false);
</script>

<template>
    <section
        v-if="errors.length || warnings.length || unmapped.length || notes"
        class="ds-card p-6"
    >
        <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Checks</h2>

        <!-- Recomputed from the card, not taken on the model's word. -->
        <div v-if="errors.length" class="mt-4 space-y-2">
            <div
                v-for="(issue, i) in errors"
                :key="`e${i}`"
                class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-fg"
            >
                <div class="flex items-start gap-2">
                    <XCircle class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <span>{{ issue.message }}</span>
                </div>

                <!-- Only range failures carry a key. A sum that doesn't
                     reconcile has nothing to approve — the number is wrong, not
                     merely unusual. -->
                <label
                    v-if="issue.override"
                    class="mt-2 flex cursor-pointer items-start gap-2 border-t border-destructive/25 pt-2 pl-6 text-xs text-fg-muted"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 size-3.5 shrink-0 cursor-pointer accent-lime-500"
                        :checked="approved.includes(issue.override)"
                        @change="approve(issue.override, ($event.target as HTMLInputElement).checked)"
                    />
                    <span>
                        Store this value as read — the card is official.
                        <span class="text-fg-subtle">It's kept exactly as printed, not corrected.</span>
                    </span>
                </label>
            </div>
            <p class="text-xs text-fg-subtle">
                These numbers don't add up. Check them against the card before accepting the sections they affect —
                a value that can't be stored is left out rather than saved wrong, unless you approve it above.
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

        <!-- The model's own account of the read. Worth as much as the checks
             above: a field left blank on purpose is indistinguishable from one
             that was missed, and this is the only place that difference is
             stated. Kept verbatim, line breaks and all — it's prose, and
             summarising it here would lose the specifics that make it useful. -->
        <div v-if="notes" class="mt-5 border-t border-line pt-4">
            <button
                type="button"
                class="flex w-full cursor-pointer items-center gap-1.5 text-left text-sm font-medium text-fg transition hover:text-lime-500 focus:outline-none focus-visible:ring-1 focus-visible:ring-line-lime"
                :aria-expanded="notesOpen"
                @click="notesOpen = !notesOpen"
            >
                <ScrollText class="size-4 shrink-0 text-fg-subtle" />
                Reader's notes
                <ChevronDown
                    class="size-4 shrink-0 text-fg-subtle transition-transform"
                    :class="notesOpen ? 'rotate-180' : ''"
                />
            </button>
            <p
                class="mt-3 text-sm leading-relaxed text-fg-muted"
                :class="notesOpen ? 'whitespace-pre-line' : 'line-clamp-1'"
            >
                {{ notes }}
            </p>
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
