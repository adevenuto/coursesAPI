<script setup lang="ts">
import { computed, ref } from 'vue';
import { Check, MapPin, X } from '@lucide/vue';
import EditableMap from './EditableMap.vue';

interface Green {
    hole: number;
    lat: number;
    lng: number;
}

const props = defineProps<{
    mapsKey: string | null;
    lat: number | string | null;
    lng: number | string | null;
    holeCount: number | string;
}>();

const greens = defineModel<Green[]>({ required: true });

const activeHole = ref(1);

const holes = computed(() => {
    const n = Number(props.holeCount) || 0;
    return Array.from({ length: n }, (_, i) => i + 1);
});

const centerLat = computed(() => Number(props.lat));
const centerLng = computed(() => Number(props.lng));
const hasCoords = computed(() => Number.isFinite(centerLat.value) && Number.isFinite(centerLng.value) && props.lat !== '' && props.lng !== '');
const setCount = computed(() => greens.value.filter((g) => g.hole <= (Number(props.holeCount) || 0)).length);

function greenFor(hole: number): Green | undefined {
    return greens.value.find((g) => g.hole === hole);
}

function upsert(hole: number, lat: number, lng: number) {
    const existing = greens.value.find((g) => g.hole === hole);
    if (existing) {
        existing.lat = lat;
        existing.lng = lng;
    } else {
        greens.value = [...greens.value, { hole, lat, lng }].sort((a, b) => a.hole - b.hole);
    }
}

function nextUnset(): number {
    for (const h of holes.value) {
        if (!greenFor(h)) return h;
    }
    return activeHole.value;
}

function onMapClick(p: { lat: number; lng: number }) {
    upsert(activeHole.value, p.lat, p.lng);
    activeHole.value = nextUnset();
}

function onMarkerDrag(p: { hole: number; lat: number; lng: number }) {
    upsert(p.hole, p.lat, p.lng);
}

function clearHole(hole: number) {
    greens.value = greens.value.filter((g) => g.hole !== hole);
    activeHole.value = hole;
}
</script>

<template>
    <div>
        <div v-if="!mapsKey" class="rounded-lg border border-dashed border-line p-6 text-center text-sm text-fg-subtle">
            Add a Google Maps key to enable the green-center editor.
        </div>
        <div v-else-if="!hasCoords" class="rounded-lg border border-dashed border-line p-6 text-center text-sm text-fg-subtle">
            Set the course latitude/longitude above first — the map centers on the course.
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-[220px_1fr]">
            <!-- hole list -->
            <div>
                <p class="mb-2 font-mono text-[11px] tracking-widest text-fg-subtle uppercase">
                    {{ setCount }}/{{ holes.length }} placed
                </p>
                <div class="max-h-[60vh] space-y-1 overflow-y-auto pr-1">
                    <div
                        v-for="h in holes"
                        :key="h"
                        role="button"
                        tabindex="0"
                        class="flex w-full cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition focus:outline-none focus-visible:border-line-lime"
                        :class="activeHole === h ? 'border-line-lime bg-ink-800 text-fg' : 'border-line text-fg-muted hover:text-fg'"
                        @click="activeHole = h"
                        @keydown.enter="activeHole = h"
                        @keydown.space.prevent="activeHole = h"
                    >
                        <span
                            class="grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                            :class="greenFor(h) ? 'bg-lime-500 text-[#0a1400]' : 'border border-line text-fg-subtle'"
                        >
                            <Check v-if="greenFor(h)" class="size-3" />
                            <span v-else>{{ h }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block">Hole {{ h }}</span>
                            <span v-if="greenFor(h)" class="block truncate font-mono text-[10px] text-fg-subtle">
                                {{ greenFor(h)!.lat.toFixed(5) }}, {{ greenFor(h)!.lng.toFixed(5) }}
                            </span>
                        </span>
                        <button
                            v-if="greenFor(h)"
                            type="button"
                            class="shrink-0 cursor-pointer text-fg-subtle hover:text-destructive"
                            aria-label="Clear hole"
                            @click.stop="clearHole(h)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                </div>
                <p class="mt-3 flex items-center gap-1.5 text-xs text-fg-subtle">
                    <MapPin class="size-3.5 text-lime-500" />
                    Click the green's center for <span class="font-medium text-fg">hole {{ activeHole }}</span>.
                </p>
            </div>

            <!-- map -->
            <div class="h-[60vh] overflow-hidden rounded-xl border border-line">
                <EditableMap
                    :maps-key="mapsKey"
                    :lat="centerLat"
                    :lng="centerLng"
                    :greens="greens"
                    :active-hole="activeHole"
                    @map-click="onMapClick"
                    @marker-drag="onMarkerDrag"
                    @marker-click="activeHole = $event"
                />
            </div>
        </div>
    </div>
</template>
