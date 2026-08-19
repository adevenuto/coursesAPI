<script setup lang="ts">
import { computed, nextTick, onMounted, watch } from 'vue';
import { ChevronDown, ChevronUp, Copy, Plus, Trash2 } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { resolveTeeColor, type TeeColorConfig } from '@/lib/teeColor';

interface Hole {
    hole: number;
    par: number | null;
    length: number | null;
    handicap: number | null;
    handicapWomen: number | null;
}
interface Teebox {
    name: string;
    color: string | null;
    secondaryColor: string | null;
    courseRating: number | null;
    courseRatingWomen: number | null;
    slope: number | null;
    slopeWomen: number | null;
    totalYardage: number | null;
    holes: Hole[];
}

const props = withDefaults(
    defineProps<{
        /** Palette + vocabulary, from App\Support\TeeColor via the controller. */
        teeColors: TeeColorConfig;
        /** Hole count is only selectable while creating a course. */
        canSetHoleCount?: boolean;
    }>(),
    { canSetHoleCount: false },
);

const presets = computed(() => props.teeColors.palette);

const teeboxes = defineModel<Teebox[]>({ required: true });
const holeCount = defineModel<number>('holeCount', { required: true });

// Ensure each teebox has exactly holes 1..holeCount (preserving existing values).
function reconcile(n: number) {
    for (const tee of teeboxes.value) {
        const byHole = new Map(tee.holes.map((h) => [h.hole, h]));
        tee.holes = Array.from({ length: n }, (_, i) => byHole.get(i + 1) ?? { hole: i + 1, par: null, length: null, handicap: null, handicapWomen: null });
    }
}

onMounted(() => reconcile(holeCount.value));
watch(holeCount, (n) => reconcile(Number(n) || 0));

function addTeebox() {
    const n = Number(holeCount.value) || 18;
    teeboxes.value = [
        ...teeboxes.value,
        {
            name: '',
            color: null,
            secondaryColor: null,
            courseRating: null,
            courseRatingWomen: null,
            slope: null,
            slopeWomen: null,
            totalYardage: null,
            holes: Array.from({ length: n }, (_, i) => ({ hole: i + 1, par: null, length: null, handicap: null, handicapWomen: null })),
        },
    ];
}

function removeTeebox(i: number) {
    teeboxes.value = teeboxes.value.filter((_, idx) => idx !== i);
}

function move(i: number, dir: -1 | 1) {
    const j = i + dir;
    if (j < 0 || j >= teeboxes.value.length) return;
    const next = [...teeboxes.value];
    [next[i], next[j]] = [next[j], next[i]];
    teeboxes.value = next;
}

function applyPreset(tee: Teebox, p: { name: string; color: string }) {
    tee.color = p.color;
    if (!tee.name) tee.name = p.name;
}

// Fill the swatch from the name as it's typed — "Burgundy" and "Blue/White"
// resolve just as they would on a scan. Deliberately not immediate: opening an
// existing course must not silently rewrite its colours, only typing does.
// A colour already set is never overwritten; that was a deliberate choice.
watch(
    () => teeboxes.value.map((tee) => tee.name),
    (names, before) => {
        names.forEach((name, i) => {
            if (before && name === before[i]) return;

            const tee = teeboxes.value[i];
            if (!tee || tee.color) return;

            const { color, secondaryColor } = resolveTeeColor(name, props.teeColors);
            if (!color) return;

            tee.color = color;
            if (secondaryColor && !tee.secondaryColor) tee.secondaryColor = secondaryColor;
        });
    },
);

// Total yards is derived from the hole yards. It's recomputed only when the user
// edits one of this teebox's yards (never on load) so a stored total isn't
// clobbered until the yards are actually touched.
function recomputeTotal(tee: Teebox) {
    // nextTick so v-model.number has flushed the just-typed hole length first.
    nextTick(() => {
        const yards = tee.holes.reduce((sum, h) => sum + (Number(h.length) || 0), 0);
        tee.totalYardage = yards > 0 ? yards : null;
    });
}

/**
 * Every teebox needs a name — the server enforces it (teeboxes.*.name is
 * required), and an unnamed tee is meaningless on a scorecard. Blocking here
 * stops unnamed cards piling up only to fail on save.
 */
const hasUnnamedTeebox = computed(() => teeboxes.value.some((tee) => !String(tee.name ?? '').trim()));

const filled = (value: unknown) => value !== null && value !== undefined && value !== '';

/** A teebox nobody has entered any hole data into yet. */
function isBlank(tee: Teebox): boolean {
    return tee.holes.every(
        (h) => !filled(h.par) && !filled(h.length) && !filled(h.handicap) && !filled(h.handicapWomen),
    );
}

/**
 * Seed a blank teebox from the one above it, copying every hole value that is
 * actually set — par, yardage and stroke index.
 *
 * Par and stroke index are properties of the hole and carry across unchanged;
 * yardage does differ per tee, but starting from the neighbouring card and
 * adjusting is far less work than typing 18 numbers from scratch.
 *
 * Deliberately not copied: course rating and slope. Those are measured per tee,
 * so an inherited value would look authoritative while being wrong.
 */
function copyFromPrevious(index: number) {
    const source = teeboxes.value[index - 1];
    const target = teeboxes.value[index];

    if (!source || !target) return;

    for (const hole of target.holes) {
        const from = source.holes.find((h) => h.hole === hole.hole);

        if (!from) continue;

        if (filled(from.par)) hole.par = from.par;
        if (filled(from.length)) hole.length = from.length;
        if (filled(from.handicap)) hole.handicap = from.handicap;
        if (filled(from.handicapWomen)) hole.handicapWomen = from.handicapWomen;
    }

    // The total only recalculates on the yardage input event, which copying
    // never fires.
    recomputeTotal(target);
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <!-- Only offered while creating: changing the count on an existing
                 course reconciles every teebox to 1..n, which silently discards
                 the holes beyond it. -->
            <label v-if="canSetHoleCount" class="flex items-center gap-2 text-sm text-fg-muted">
                Holes
                <select
                    v-model.number="holeCount"
                    class="rounded-lg border border-line bg-ink-800 px-2 py-1.5 text-sm text-fg focus:border-line-lime focus:outline-none"
                >
                    <option :value="9">9</option>
                    <option :value="18">18</option>
                </select>
            </label>
            <div class="ml-auto flex items-center gap-2">
                <span v-if="hasUnnamedTeebox" class="text-xs text-fg-subtle"> Name every teebox first </span>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="hasUnnamedTeebox"
                    :title="hasUnnamedTeebox ? 'Give every teebox a name before adding another' : undefined"
                    @click="addTeebox"
                >
                    <Plus class="size-4" /> Add teebox
                </Button>
            </div>
        </div>

        <p v-if="!teeboxes.length" class="mt-4 rounded-lg border border-dashed border-line p-6 text-center text-sm text-fg-subtle">
            No teeboxes yet. Add one to build the scorecard.
        </p>

        <div v-for="(tee, i) in teeboxes" :key="i" class="mt-4 rounded-xl border border-line bg-ink-850 p-4">
            <!-- teebox header -->
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex size-5 shrink-0 overflow-hidden rounded-full border border-line">
                    <span class="h-full flex-1" :style="{ background: tee.color || 'transparent' }" />
                    <span v-if="tee.secondaryColor" class="h-full flex-1" :style="{ background: tee.secondaryColor }" />
                </span>
                <Input v-model="tee.name" placeholder="Tee name (e.g. Blue)" class="w-40" maxlength="60" />
                <label class="flex items-center gap-1.5 text-xs text-fg-subtle">
                    Combine
                    <select
                        class="rounded-lg border border-line bg-ink-800 px-2 py-1 text-sm text-fg focus:border-line-lime focus:outline-none"
                        :value="tee.secondaryColor ?? ''"
                        @change="tee.secondaryColor = ($event.target as HTMLSelectElement).value || null"
                    >
                        <option value="">None</option>
                        <option v-for="p in presets" :key="p.name" :value="p.color">{{ p.name }}</option>
                    </select>
                </label>
                <div class="ml-auto flex items-center gap-1">
                    <!-- Only while this card is still blank: once it holds data,
                         copying over it would silently overwrite real edits. -->
                    <button
                        v-if="i > 0 && isBlank(tee) && !isBlank(teeboxes[i - 1])"
                        type="button"
                        class="mr-1 inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-line px-2 py-1 text-xs text-fg-muted transition hover:border-line-lime hover:text-fg"
                        :title="`Copy par, yardage and stroke index from ${teeboxes[i - 1].name || 'the teebox above'}, then adjust the yardages`"
                        @click="copyFromPrevious(i)"
                    >
                        <Copy class="size-3.5" /> Copy from {{ teeboxes[i - 1].name || 'previous tee' }}
                    </button>
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted enabled:cursor-pointer enabled:hover:text-fg disabled:opacity-40" :disabled="i === 0" aria-label="Move up" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted enabled:cursor-pointer enabled:hover:text-fg disabled:opacity-40" :disabled="i === teeboxes.length - 1" aria-label="Move down" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-destructive enabled:cursor-pointer hover:border-destructive/50" aria-label="Remove teebox" @click="removeTeebox(i)"><Trash2 class="size-4" /></button>
                </div>
            </div>

            <!-- presets -->
            <div class="mt-3 flex flex-wrap gap-1.5">
                <button
                    v-for="p in presets"
                    :key="p.name"
                    type="button"
                    class="flex cursor-pointer items-center gap-1.5 rounded-full border px-2 py-1 text-xs transition hover:border-line-lime hover:text-fg"
                    :class="
                        p.color === tee.color
                            ? 'border-line-lime bg-ink-800 text-fg'
                            : 'border-line text-fg-muted'
                    "
                    :aria-pressed="p.color === tee.color"
                    @click="applyPreset(tee, p)"
                >
                    <span class="size-2.5 rounded-full" :style="{ background: p.color }" /> {{ p.name }}
                </button>
            </div>

            <!-- meta (rating + slope carry men's / women's values; total is shared) -->
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="text-xs text-fg-subtle">
                    Rating <span class="text-fg-subtle/70">· M / W</span>
                    <div class="mt-1 flex gap-2">
                        <Input v-model="tee.courseRating" type="number" step="0.1" aria-label="Men's rating" />
                        <Input v-model="tee.courseRatingWomen" type="number" step="0.1" aria-label="Women's rating" />
                    </div>
                </div>
                <div class="text-xs text-fg-subtle">
                    Slope <span class="text-fg-subtle/70">· M / W</span>
                    <div class="mt-1 flex gap-2">
                        <Input v-model="tee.slope" type="number" aria-label="Men's slope" />
                        <Input v-model="tee.slopeWomen" type="number" aria-label="Women's slope" />
                    </div>
                </div>
                <label class="text-xs text-fg-subtle">Total yds<Input :model-value="tee.totalYardage ?? ''" type="number" class="mt-1" disabled title="Auto-calculated from the hole yards" /></label>
            </div>

            <!-- holes grid -->
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[520px] border-separate border-spacing-1 text-center text-sm">
                    <thead>
                        <tr class="font-mono text-[10px] tracking-widest text-fg-subtle uppercase">
                            <th class="text-left">Hole</th>
                            <th v-for="h in tee.holes" :key="h.hole">{{ h.hole }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-left font-mono text-[10px] tracking-widest text-fg-subtle uppercase">Par</td>
                            <td v-for="h in tee.holes" :key="h.hole"><input v-model.number="h.par" type="number" class="score-cell" /></td>
                        </tr>
                        <tr>
                            <td class="text-left font-mono text-[10px] tracking-widest text-fg-subtle uppercase">Yds</td>
                            <td v-for="h in tee.holes" :key="h.hole"><input v-model.number="h.length" type="number" class="score-cell" @input="recomputeTotal(tee)" /></td>
                        </tr>
                        <tr>
                            <td class="text-left font-mono text-[10px] tracking-widest text-fg-subtle uppercase">Hcp</td>
                            <td v-for="h in tee.holes" :key="h.hole"><input v-model.number="h.handicap" type="number" class="score-cell" /></td>
                        </tr>
                        <tr>
                            <td class="text-left font-mono text-[10px] tracking-widest text-fg-subtle uppercase">Hcp W</td>
                            <td v-for="h in tee.holes" :key="h.hole"><input v-model.number="h.handicapWomen" type="number" class="score-cell" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
.score-cell {
    width: 3rem;
    border-radius: 6px;
    border: 1px solid var(--border-subtle);
    background: var(--ink-800);
    padding: 4px 2px;
    text-align: center;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
}
.score-cell:focus {
    outline: none;
    border-color: var(--border-lime);
}
/* hide number spinners for a tidy grid */
.score-cell::-webkit-inner-spin-button,
.score-cell::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
</style>
