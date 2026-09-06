<script setup lang="ts">
import { computed } from 'vue';
import { useInView } from '@/composables/useInView';
import { resolveTeeColor, type TeeColorConfig } from '@/lib/teeColor';

/**
 * A course's scorecard, laid out the way a printed card reads: tees down the
 * side, holes across the top, par and stroke index beneath.
 *
 * Presentational by design — it takes the scorecard it is given and fetches
 * nothing. That keeps it usable in the three places it is wanted (this page, a
 * marketing demo, a preview beside the editor) and makes it the reference
 * layout if the same card is ever rendered server-side as SVG.
 */

export interface ScorecardHole {
    hole: number;
    par: number | null;
    yards: number | null;
    handicap: number | null;
}

export interface ScorecardTee {
    name: string | null;
    rating: number | null;
    slope: number | null;
    total_yards: number | null;
    /** As stored on the course, so the swatch matches the editor's chip. */
    color?: string | null;
    secondary_color?: string | null;
    holes: ScorecardHole[];
}

const props = withDefaults(
    defineProps<{
        scorecard: { hole_count: number | null; teeboxes: ScorecardTee[] } | null;
        teeColors?: TeeColorConfig | null;
        animate?: boolean;
    }>(),
    { teeColors: null, animate: true },
);

// One observer for the whole card. `mk-reveal` is already exempt under
// prefers-reduced-motion, so the reveal degrades to an instant appearance with
// no check here — a hand-rolled JS animation would have bypassed that.
const { target, inView } = useInView(0.1);
const revealed = computed(() => ! props.animate || inView.value);

const tees = computed(() => props.scorecard?.teeboxes ?? []);

/** Longest first, the way a card reads top to bottom. Nulls sort last. */
const ordered = computed(() =>
    [...tees.value].sort((a, b) => (b.total_yards ?? -1) - (a.total_yards ?? -1)),
);

/**
 * Columns come from every hole number any tee carries, not the first tee's.
 *
 * 1,303 courses have tees that disagree on hole count — an eighteen-hole card
 * with a nine-hole forward tee is common. Taking columns from one tee would
 * render a shorter tee's nine cells under eighteen headers and slide its
 * totals into the wrong place.
 */
const holeNumbers = computed(() =>
    [...new Set(tees.value.flatMap((t) => t.holes.map((h) => h.hole)))].sort((a, b) => a - b),
);
const splitsNines = computed(() => holeNumbers.value.length === 18);
const front = computed(() => holeNumbers.value.filter((n) => n <= 9));
const back = computed(() => holeNumbers.value.filter((n) => n > 9));

function byNumber(tee: ScorecardTee): Map<number, ScorecardHole> {
    return new Map(tee.holes.map((h) => [h.hole, h]));
}

/**
 * Totals over hole NUMBERS rather than array positions — same ragged-tee
 * reason, and it stops assuming the holes arrive in order. One missing cell
 * makes the total a guess, so it reports nothing rather than a sum that
 * silently omits a hole.
 */
function total(tee: ScorecardTee, key: 'par' | 'yards', from: number, to: number): number | null {
    const holes = tee.holes.filter((h) => h.hole >= from && h.hole <= to);
    if (holes.length === 0 || holes.some((h) => h[key] === null)) return null;

    return holes.reduce((n, h) => n + (h[key] ?? 0), 0);
}

/**
 * Par and stroke index print once per card, not once per tee. Take the first
 * tee that states a value for the hole — they agree on all but a handful of
 * tee-varying-par courses, and disagreement is not something a single row can
 * express anyway.
 */
function shared(hole: number, key: 'par' | 'handicap'): number | null {
    for (const tee of ordered.value) {
        const value = byNumber(tee).get(hole)?.[key];
        if (value !== null && value !== undefined) return value;
    }

    return null;
}

function sharedTotal(from: number, to: number): number | null {
    const values = holeNumbers.value
        .filter((n) => n >= from && n <= to)
        .map((n) => shared(n, 'par'));
    if (values.length === 0 || values.some((v) => v === null)) return null;

    return values.reduce((n: number, v) => n + (v ?? 0), 0);
}

/**
 * The tee's colour.
 *
 * Stored value first, so a swatch here matches the chip the editor shows — that
 * colour can be overridden by hand, and 20,833 of 22,067 courses carry one.
 * Name resolution is only the fallback for the tees that have none, which is
 * also what the editor prefills with.
 */
function tint(tee: ScorecardTee): string | null {
    if (tee.color) return tee.color;
    if (! props.teeColors) return null;

    return resolveTeeColor(tee.name, props.teeColors).color;
}

/**
 * The tee's second colour, for a split marker ("Gold/Green"). Same precedence as
 * the primary: stored first, name resolution only as the fallback.
 */
function second(tee: ScorecardTee): string | null {
    if (tee.secondary_color) return tee.secondary_color;
    if (! props.teeColors) return null;

    return resolveTeeColor(tee.name, props.teeColors).secondaryColor;
}

/** Blank rows to write scores into, as the printed card has. */
const PLAYER_ROWS = 4;

/** An absent cell reads as absent. Stroke index is missing on a third of the
 *  catalogue, and a blank row would hide that rather than state it. */
function cell(value: number | null): string {
    return value === null ? '—' : String(value);
}
</script>

<template>
    <section v-if="ordered.length" ref="target" class="mt-12">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Scorecard</h2>
            <span class="font-mono text-[11px] text-fg-subtle">
                {{ ordered.length }} tee{{ ordered.length === 1 ? '' : 's' }}
            </span>
        </div>

        <div
            class="ds-card mt-4 min-w-0 overflow-hidden p-0"
            :class="revealed ? 'mk-reveal' : 'opacity-0'"
        >
            <!-- Eighteen holes never fit a phone, so the card scrolls inside
                 itself rather than pushing the page sideways. The tee column is
                 pinned so a row stays identifiable once it does. -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-center font-mono text-[11px]">
                    <colgroup>
                        <col style="width: 150px" />
                        <col v-for="n in holeNumbers" :key="`c${n}`" />
                        <col v-if="splitsNines" style="width: 46px" />
                        <col v-if="splitsNines" style="width: 46px" />
                        <col style="width: 52px" />
                    </colgroup>

                    <thead>
                        <tr class="bg-white/[0.04] text-fg-subtle">
                            <th
                                class="sticky left-0 z-10 border-r border-b border-line bg-ink-800 px-3 py-2 text-left font-normal tracking-[0.14em] uppercase"
                            >
                                Hole
                            </th>
                            <th v-for="n in front" :key="n" class="border-r border-b border-line/60 px-1 py-2 font-normal">
                                {{ n }}
                            </th>
                            <th v-if="splitsNines" class="border-r border-b border-line px-1 py-2 font-normal text-lime-500">Out</th>
                            <th v-for="n in back" :key="n" class="border-r border-b border-line/60 px-1 py-2 font-normal">
                                {{ n }}
                            </th>
                            <th v-if="splitsNines" class="border-r border-b border-line px-1 py-2 font-normal text-lime-500">In</th>
                            <th class="border-b border-line px-1 py-2 font-normal text-lime-500">Tot</th>
                        </tr>
                    </thead>

                    <tbody>
                        <!-- One row per tee, banded in the tee's own colour. -->
                        <tr
                            v-for="(tee, i) in ordered"
                            :key="`${tee.name}-${i}`"
                            :style="tint(tee)
                                ? { backgroundColor: `color-mix(in oklab, ${tint(tee)} 20%, transparent)` }
                                : undefined"
                        >
                            <th
                                class="sticky left-0 z-10 border-r border-b border-line px-3 py-1.5 text-left font-normal text-fg"
                                :style="{
                                    backgroundColor: 'var(--ink-800)',
                                    backgroundImage: tint(tee)
                                        ? `linear-gradient(color-mix(in oklab, ${tint(tee)} 20%, transparent), color-mix(in oklab, ${tint(tee)} 20%, transparent))`
                                        : undefined,
                                }"
                            >
                                <span class="flex items-center gap-2 whitespace-nowrap">
                                    <span class="inline-flex size-4 shrink-0 overflow-hidden rounded-full border border-line">
                                        <span
                                            class="h-full flex-1 ring-1 ring-line ring-inset"
                                            :style="{ background: tint(tee) || 'transparent' }"
                                        />
                                        <span
                                            v-if="second(tee)"
                                            class="h-full flex-1"
                                            :style="{ background: second(tee) as string }"
                                        />
                                    </span>
                                    <span class="truncate">{{ tee.name || 'Tee' }}</span>
                                </span>
                            </th>
                            <td v-for="n in front" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg">
                                {{ cell(byNumber(tee).get(n)?.yards ?? null) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(total(tee, 'yards', 1, 9)) }}
                            </td>
                            <td v-for="n in back" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg">
                                {{ cell(byNumber(tee).get(n)?.yards ?? null) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(total(tee, 'yards', 10, 18)) }}
                            </td>
                            <td class="border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(total(tee, 'yards', 1, Infinity)) }}
                            </td>
                        </tr>

                        <!-- Par and stroke index print once for the card. -->
                        <tr class="bg-white/[0.03]">
                            <th class="sticky left-0 z-10 border-r border-b border-line bg-ink-800 px-3 py-1.5 text-left font-normal text-fg-subtle">
                                Par
                            </th>
                            <td v-for="n in front" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg">
                                {{ cell(shared(n, 'par')) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(sharedTotal(1, 9)) }}
                            </td>
                            <td v-for="n in back" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg">
                                {{ cell(shared(n, 'par')) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(sharedTotal(10, 18)) }}
                            </td>
                            <td class="border-b border-line/40 px-1 py-1.5 font-semibold text-lime-500">
                                {{ cell(sharedTotal(1, Infinity)) }}
                            </td>
                        </tr>

                        <tr class="bg-white/[0.03]">
                            <th class="sticky left-0 z-10 border-r border-b border-line bg-ink-800 px-3 py-1.5 text-left font-normal text-fg-subtle">
                                Handicap
                            </th>
                            <td v-for="n in front" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg-muted">
                                {{ cell(shared(n, 'handicap')) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 text-fg-subtle">—</td>
                            <td v-for="n in back" :key="n" class="border-r border-b border-line/40 px-1 py-1.5 text-fg-muted">
                                {{ cell(shared(n, 'handicap')) }}
                            </td>
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-1.5 text-fg-subtle">—</td>
                            <td class="border-b border-line/40 px-1 py-1.5 text-fg-subtle">—</td>
                        </tr>

                        <!-- Blank rows, as the printed card leaves for scores.
                             Presentational only: nothing here is editable or
                             submitted, it is what makes the grid read as a card. -->
                        <tr v-for="row in PLAYER_ROWS" :key="`p${row}`" aria-hidden="true">
                            <th class="sticky left-0 z-10 border-r border-b border-line bg-ink-800 px-3 py-2 text-left font-normal text-fg-subtle/40">
                                <span class="text-[10px]">Player {{ row }}</span>
                            </th>
                            <td
                                v-for="n in holeNumbers"
                                :key="n"
                                class="border-r border-b border-line/40 px-1 py-2"
                            />
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-2" />
                            <td v-if="splitsNines" class="border-r border-b border-line/40 px-1 py-2" />
                            <td class="border-b border-line/40 px-1 py-2" />
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Rating and slope belong to the tee, not to a hole, so they sit
                 below the grid. Men's figures only: the scorecard accessor falls
                 back to the men's value when a course carries no distinct
                 women's one, so a second column would present a copy as data. -->
            <dl class="flex flex-wrap gap-x-6 gap-y-2 border-t border-line px-4 py-4 font-mono text-[11px] sm:px-5">
                <div v-for="(tee, i) in ordered" :key="`r${i}`" class="flex items-center gap-2">
                    <span class="inline-flex size-3.5 shrink-0 overflow-hidden rounded-full border border-line">
                        <span
                            class="h-full flex-1 ring-1 ring-line ring-inset"
                            :style="{ background: tint(tee) || 'transparent' }"
                        />
                        <span
                            v-if="second(tee)"
                            class="h-full flex-1"
                            :style="{ background: second(tee) as string }"
                        />
                    </span>
                    <dt class="text-fg-subtle">{{ tee.name || 'Tee' }}</dt>
                    <dd class="text-fg">
                        <template v-if="tee.rating !== null || tee.slope !== null">
                            {{ tee.rating !== null ? tee.rating.toFixed(1) : '—' }}
                            <span class="text-fg-subtle">/</span>
                            {{ tee.slope ?? '—' }}
                        </template>
                        <span v-else class="text-fg-subtle">unrated</span>
                    </dd>
                </div>
                <span class="text-fg-subtle">rating / slope</span>
            </dl>
        </div>
    </section>
</template>
