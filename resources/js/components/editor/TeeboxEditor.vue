<script setup lang="ts">
import { nextTick, onMounted, watch } from 'vue';
import { ChevronDown, ChevronUp, Copy, Plus, Trash2 } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

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

const teeboxes = defineModel<Teebox[]>({ required: true });
const holeCount = defineModel<number>('holeCount', { required: true });

// Common tee names → representative colors.
const PRESETS = [
    { name: 'Black', color: '#111827' },
    { name: 'Blue', color: '#1D4ED8' },
    { name: 'White', color: '#E5E7EB' },
    { name: 'Gold', color: '#CA8A04' },
    { name: 'Green', color: '#15803D' },
    { name: 'Red', color: '#B91C1C' },
    { name: 'Silver', color: '#9CA3AF' },
    { name: 'Purple', color: '#7E22CE' },
    { name: 'Orange', color: '#EA580C' },
    { name: 'Yellow', color: '#EAB308' },
];

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

// Par + handicap are course-constant; copy them from the first tee to the rest.
function syncParHandicap() {
    const first = teeboxes.value[0];
    if (!first) return;
    for (const tee of teeboxes.value.slice(1)) {
        for (const h of tee.holes) {
            const src = first.holes.find((x) => x.hole === h.hole);
            if (src) {
                h.par = src.par;
                h.handicap = src.handicap;
                h.handicapWomen = src.handicapWomen;
            }
        }
    }
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-fg-muted">
                Holes
                <select
                    v-model.number="holeCount"
                    class="rounded-lg border border-line bg-ink-800 px-2 py-1.5 text-sm text-fg focus:border-line-lime focus:outline-none"
                >
                    <option :value="9">9</option>
                    <option :value="18">18</option>
                    <option :value="27">27</option>
                    <option :value="36">36</option>
                </select>
            </label>
            <div class="flex items-center gap-2">
                <Button v-if="teeboxes.length > 1" type="button" variant="ghost" size="sm" @click="syncParHandicap">
                    <Copy class="size-4" /> Sync par/handicap
                </Button>
                <Button type="button" variant="secondary" size="sm" @click="addTeebox">
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
                        <option v-for="p in PRESETS" :key="p.name" :value="p.color">{{ p.name }}</option>
                    </select>
                </label>
                <div class="ml-auto flex items-center gap-1">
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted enabled:cursor-pointer enabled:hover:text-fg disabled:opacity-40" :disabled="i === 0" aria-label="Move up" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted enabled:cursor-pointer enabled:hover:text-fg disabled:opacity-40" :disabled="i === teeboxes.length - 1" aria-label="Move down" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-line text-destructive enabled:cursor-pointer hover:border-destructive/50" aria-label="Remove teebox" @click="removeTeebox(i)"><Trash2 class="size-4" /></button>
                </div>
            </div>

            <!-- presets -->
            <div class="mt-3 flex flex-wrap gap-1.5">
                <button
                    v-for="p in PRESETS"
                    :key="p.name"
                    type="button"
                    class="flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-2 py-1 text-xs text-fg-muted transition hover:border-line-lime hover:text-fg"
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
