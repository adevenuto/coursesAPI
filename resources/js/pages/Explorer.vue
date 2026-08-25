<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { MapPinned, Plus, ScanLine } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import GlowBadge from '@/components/marketing/GlowBadge.vue';
import CourseSearch from '@/components/explorer/CourseSearch.vue';
import ResultsList from '@/components/explorer/ResultsList.vue';
import CoursesMap from '@/components/explorer/CoursesMap.vue';
import RadiusControl from '@/components/explorer/RadiusControl.vue';
import {
    clearExplorerSearch,
    readExplorerSearch,
    writeExplorerSearch,
    type StoredView,
} from '@/composables/useExplorerSearch';

interface Hit {
    id: number;
    type: 'course' | 'city' | 'state' | 'country';
    name?: string;
    label?: string;
    url: string;
}

interface Area {
    type: string;
    name: string;
    label: string;
    radius_mi?: number;
    center?: { lat: number; lng: number };
}

const props = defineProps<{
    algolia: {
        app_id: string;
        search_key: string;
        configured: boolean;
        indices: { courses: string; cities: string; states: string; countries: string };
    };
    maps: { key: string; configured: boolean };
    canEdit: boolean;
    baseUrl: string;
}>();

// Owned here rather than inside CourseSearch so it survives a page transition
// and the × can reset the whole page with it.
const query = ref('');
const search = ref<InstanceType<typeof CourseSearch> | null>(null);

const area = ref<Area | null>(null);
const courses = ref<Array<{ id: number; name: string; club: string | null; city: string | null; state: string | null; lat: number; lng: number; distance_mi?: number; url: string; green_centers_available?: boolean }>>([]);
const count = ref(0);
const capped = ref(false);
const loading = ref(false); // initial skeleton
const refreshing = ref(false); // in-place refresh (radius toggle/slider)
const bounds = ref<{ min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null>(null);

// Where the map is now, and where it was last visit. Kept apart on purpose:
// `restoreView` is a one-shot handed to the map, and letting the map's own
// idle events write back into it would re-arm the restore on every pan.
const view = ref<StoredView | null>(null);
const restoreView = ref<StoredView | null>(null);

// Map ↔ list sync: current map viewport (for filtering) + hovered course.
const viewport = ref<{ min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null>(null);
const hoveredId = ref<number | null>(null);

// The list shows only the courses currently within the map viewport.
const visibleCourses = computed(() => {
    const v = viewport.value;
    if (!v) return courses.value;
    return courses.value.filter(
        (c) => c.lat >= v.min_lat && c.lat <= v.max_lat && c.lng >= v.min_lng && c.lng <= v.max_lng,
    );
});

// Radius search (city selections only).
const selected = ref<Hit | null>(null);
const radiusOn = ref(false);
const radiusMiles = ref(25);
const center = ref<{ lat: number; lng: number } | null>(null);
let reqToken = 0;

const mapCircle = computed(() =>
    radiusOn.value && center.value
        ? { lat: center.value.lat, lng: center.value.lng, radiusMeters: radiusMiles.value * 1609.34 }
        : null,
);

/** Resolves false only when the area genuinely failed to load. */
async function loadArea(refresh: boolean): Promise<boolean> {
    const hit = selected.value;
    if (!hit) return false;

    // Show all until the map re-fits and reports its new viewport.
    viewport.value = null;

    const useRadius = radiusOn.value && hit.type === 'city';
    const url = useRadius ? `${hit.url}?radius=${radiusMiles.value}` : hit.url;
    const token = ++reqToken;

    if (refresh) {
        refreshing.value = true;
    } else {
        loading.value = true;
        area.value = null;
    }

    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        // fetch only rejects on a network error, so a 404 — a city that no
        // longer exists — otherwise sails through as a JSON error body and
        // renders as a present-but-empty area.
        if (!res.ok) throw new Error(String(res.status));
        const data = await res.json();
        if (token !== reqToken) return true; // a newer request won; not a failure
        area.value = data.area;
        courses.value = data.courses ?? [];
        count.value = data.count ?? 0;
        capped.value = !!data.capped;
        bounds.value = data.bounds ?? null;
        center.value = data.area?.center ?? null;

        return true;
    } catch {
        if (token !== reqToken) return true;
        if (!refresh) {
            area.value = { type: hit.type, name: hit.name ?? '', label: hit.label ?? '' };
            courses.value = [];
            count.value = 0;
        }

        return false;
    } finally {
        if (token === reqToken) {
            loading.value = false;
            refreshing.value = false;
        }
    }
}

function persist() {
    // Nothing worth restoring — drop the record instead of storing an empty
    // one. This also cleans up after clearAll(): watchers flush asynchronously,
    // so its own writes would otherwise land *after* it cleared the key.
    if (!query.value && !selected.value) {
        clearExplorerSearch();

        return;
    }

    writeExplorerSearch({
        q: query.value,
        hit: selected.value,
        radiusOn: radiusOn.value,
        radiusMiles: radiusMiles.value,
        view: view.value,
    });
}

function onSelect(hit: Hit) {
    // A course goes to its detail page — or straight to the editor for editors.
    if (hit.type === 'course') {
        // Snap the box back to the area being browsed. Choosing a course sets
        // the box to its name, and coming back to "Cog Hill · 2" above a map of
        // Chicago reads as a stale search; the area is what you're working
        // through, so that's what resumes.
        const area = selected.value;
        if (area) query.value = area.label ?? area.name ?? '';

        // Save before navigating, not via the watcher below: this leaves the
        // page immediately, and a watcher isn't guaranteed to flush first. This
        // is the exact trip an editor makes over and over, so losing it here
        // would defeat the whole feature.
        persist();
        router.visit(props.canEdit ? `/courses/${hit.id}/edit` : hit.url);
        return;
    }
    selected.value = hit;
    restoreView.value = null; // a freshly picked area frames itself
    loadArea(false);
}

/**
 * The box went empty by hand, not via the ×, and focus left. The map, results
 * and radius are all still on the area — the box is the only thing that went
 * blank, and a blank search field above a full map of Chicago is exactly the
 * state the × exists to produce properly. Snap it back to the area on screen.
 *
 * Also repairs what got persisted: `query` is stored, so the empty box was
 * already written to sessionStorage, and without this the next visit restores
 * the area with no term above it. Assigning here re-runs persist() with the
 * label, and doing it programmatically leaves the search itself untouched —
 * only @input searches.
 */
function revertQuery() {
    const hit = selected.value;
    if (!hit) return;

    query.value = hit.label ?? hit.name ?? '';
}

/** The × — back to a blank explorer, with nothing left to restore. */
function clearAll() {
    query.value = '';
    selected.value = null;
    area.value = null;
    courses.value = [];
    count.value = 0;
    capped.value = false;
    bounds.value = null;
    center.value = null;
    viewport.value = null;
    hoveredId.value = null;
    radiusOn.value = false;
    view.value = null;
    restoreView.value = null;
    clearExplorerSearch();
}

// Typing and radius changes; the course-select path writes synchronously above.
watch([query, selected, radiusOn, radiusMiles, view], persist);

// sessionStorage doesn't exist during the server render, so this waits for the
// client. A stored area is re-fetched rather than serialised — loadArea()
// already owns the skeleton, request-ordering and failure handling.
onMounted(async () => {
    const saved = readExplorerSearch();
    if (!saved) return;

    query.value = saved.q;
    radiusOn.value = saved.radiusOn;
    radiusMiles.value = saved.radiusMiles;
    view.value = saved.view;
    restoreView.value = saved.view;

    // Let that reach the input before searching. runSearch() drops a response
    // whose query no longer matches what the box holds, and the box reads its
    // value from a prop that hasn't propagated yet.
    await nextTick();

    // Re-run the query itself, so the box comes back live rather than holding
    // text that does nothing. The dropdown only opens when there's no area to
    // restore — otherwise it would cover the map and results it sits above.
    search.value?.runStoredQuery(saved.q, { open: !saved.hit });

    if (saved.hit) {
        selected.value = saved.hit;
        loadArea(false).then((ok) => {
            // The stored area could have been deleted since we saw it last.
            // Don't leave a stub on screen — drop it and start clean.
            if (!ok) clearAll();
        });
    }
});

// Toggling nearby refetches immediately; the slider is debounced so dragging
// doesn't spam the endpoint.
watch(radiusOn, () => {
    if (selected.value?.type === 'city') loadArea(true);
});
const refetchForRadius = useDebounceFn(() => {
    if (radiusOn.value && selected.value?.type === 'city') loadArea(true);
}, 250);
watch(radiusMiles, refetchForRadius);
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 pt-10 pb-16 sm:px-7">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <GlowBadge>Explorer</GlowBadge>
                        <span v-if="canEdit" class="ds-badge ds-badge--lime font-mono text-[10px] tracking-widest uppercase">Editor</span>
                    </div>
                    <h1 class="mt-4 font-display text-3xl font-bold tracking-tight text-fg sm:text-4xl" style="letter-spacing: -0.02em">
                        Find any course.
                    </h1>
                    <p class="mt-3 max-w-2xl text-fg-muted">
                        <template v-if="canEdit">Search to edit an existing course, or add a new one.</template>
                        <template v-else>Search 22,000+ courses by name, or jump to a city, state, or country to see everything in the area.</template>
                    </p>
                </div>

                <div v-if="canEdit" class="flex shrink-0 items-center gap-2">
                    <Link href="/scorecard-scans/create" class="ds-btn ds-btn--dark px-4 py-2.5 text-sm">
                        <ScanLine class="size-4" /> Scan a scorecard
                    </Link>
                    <Link href="/courses/create" class="ds-btn ds-btn--primary px-4 py-2.5 text-sm">
                        <Plus class="size-4" /> New course
                    </Link>
                </div>
            </div>

            <!-- not-configured (dev) state -->
            <div
                v-if="!algolia.configured"
                class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-700 dark:text-amber-400"
            >
                Search isn’t configured yet. Add your Algolia keys to <code class="font-mono">.env</code> and run the
                importer (see <code class="font-mono">docs/ALGOLIA_SETUP.md</code>) to enable the explorer.
            </div>

            <!-- min-w-0 on both columns: a grid item defaults to min-width:auto and
                 refuses to shrink below its content's intrinsic width, so a long
                 course name or the map's own DOM would widen the column past the
                 viewport. The layout clips overflow rather than scrolling it, so
                 that shows up as content sliced off the right edge. -->
            <div class="grid gap-6 lg:grid-cols-[minmax(0,440px)_1fr] lg:grid-rows-[auto_1fr]">
                <!-- search + radius -->
                <div class="flex min-w-0 flex-col gap-5 lg:col-start-1 lg:row-start-1">
                    <CourseSearch
                        ref="search"
                        v-model="query"
                        :algolia="algolia"
                        @select="onSelect"
                        @clear="clearAll"
                        @revert="revertQuery"
                    />
                    <RadiusControl
                        v-if="area?.type === 'city'"
                        v-model:enabled="radiusOn"
                        v-model:miles="radiusMiles"
                        :city="area.name"
                    />
                </div>

                <!-- Map. Ahead of the results in source order so that on mobile it
                     sits directly under the search box rather than being buried
                     below a long list; on lg it is placed back into the right
                     column, spanning both rows, and stays sticky while the results
                     scroll. -->
                <div class="ds-card relative min-h-[420px] min-w-0 overflow-hidden lg:sticky lg:top-24 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:h-[calc(100vh-8rem)] lg:min-h-0 lg:self-start">
                    <CoursesMap
                        v-if="maps.configured"
                        :maps-key="maps.key"
                        :courses="courses"
                        :bounds="bounds"
                        :circle="mapCircle"
                        :hovered-id="hoveredId"
                        :restore-view="restoreView"
                        @viewport="viewport = $event"
                        @view="view = $event"
                        @marker-hover="hoveredId = $event"
                    />
                    <template v-else>
                        <div class="aurora absolute inset-0 opacity-40" />
                        <div class="relative flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                            <span class="ds-icon-tile"><MapPinned class="size-5 text-lime-500" /></span>
                            <p class="font-display text-lg font-semibold text-fg">Map not configured</p>
                            <p class="max-w-xs text-sm text-fg-subtle">
                                Add a Google Maps key to <code class="font-mono">.env</code> to enable the map.
                            </p>
                        </div>
                    </template>
                </div>

                <!-- results: last on mobile, back beneath the search box on lg -->
                <div class="min-w-0 lg:col-start-1 lg:row-start-2">
                    <ResultsList
                        :area="area"
                        :courses="visibleCourses"
                        :count="count"
                        :loaded="courses.length"
                        :capped="capped"
                        :loading="loading"
                        :refreshing="refreshing"
                        :hovered-id="hoveredId"
                        @hover="hoveredId = $event"
                    />
                </div>
            </div>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
