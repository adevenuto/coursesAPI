<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { MapPinned } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import GlowBadge from '@/components/marketing/GlowBadge.vue';
import CourseSearch from '@/components/explorer/CourseSearch.vue';
import ResultsList from '@/components/explorer/ResultsList.vue';
import CoursesMap from '@/components/explorer/CoursesMap.vue';
import RadiusControl from '@/components/explorer/RadiusControl.vue';

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
    baseUrl: string;
}>();

const area = ref<Area | null>(null);
const courses = ref<Array<{ id: number; name: string; club: string | null; city: string | null; state: string | null; distance_mi?: number; url: string }>>([]);
const count = ref(0);
const capped = ref(false);
const loading = ref(false); // initial skeleton
const refreshing = ref(false); // in-place refresh (radius toggle/slider)
const bounds = ref<{ min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null>(null);

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

async function loadArea(refresh: boolean) {
    const hit = selected.value;
    if (!hit) return;

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
        const data = await res.json();
        if (token !== reqToken) return; // a newer request won
        area.value = data.area;
        courses.value = data.courses ?? [];
        count.value = data.count ?? 0;
        capped.value = !!data.capped;
        bounds.value = data.bounds ?? null;
        center.value = data.area?.center ?? null;
    } catch {
        if (token !== reqToken) return;
        if (!refresh) {
            area.value = { type: hit.type, name: hit.name ?? '', label: hit.label ?? '' };
            courses.value = [];
            count.value = 0;
        }
    } finally {
        if (token === reqToken) {
            loading.value = false;
            refreshing.value = false;
        }
    }
}

function onSelect(hit: Hit) {
    // A course goes straight to its detail page.
    if (hit.type === 'course') {
        router.visit(hit.url);
        return;
    }
    selected.value = hit;
    loadArea(false);
}

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
    <Head title="Course Explorer — GCA">
        <meta name="description" content="Search 22,000+ golf courses by name, city, state, or country." />
    </Head>

    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 pt-10 pb-16 sm:px-7">
            <div class="mb-8">
                <GlowBadge>Explorer</GlowBadge>
                <h1 class="mt-4 font-display text-3xl font-bold tracking-tight text-fg sm:text-4xl" style="letter-spacing: -0.02em">
                    Find any course.
                </h1>
                <p class="mt-3 max-w-2xl text-fg-muted">
                    Search 22,000+ courses by name, or jump to a city, state, or country to see everything in the area.
                </p>
            </div>

            <!-- not-configured (dev) state -->
            <div
                v-if="!algolia.configured"
                class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-700 dark:text-amber-400"
            >
                Search isn’t configured yet. Add your Algolia keys to <code class="font-mono">.env</code> and run the
                importer (see <code class="font-mono">docs/ALGOLIA_SETUP.md</code>) to enable the explorer.
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,440px)_1fr]">
                <!-- left: search + results -->
                <div class="flex flex-col gap-5">
                    <CourseSearch :algolia="algolia" @select="onSelect" />
                    <RadiusControl
                        v-if="area?.type === 'city'"
                        v-model:enabled="radiusOn"
                        v-model:miles="radiusMiles"
                        :city="area.name"
                    />
                    <ResultsList
                        :area="area"
                        :courses="courses"
                        :count="count"
                        :capped="capped"
                        :loading="loading"
                        :refreshing="refreshing"
                    />
                </div>

                <!-- right: clustered map — sticky as the results list scrolls -->
                <div class="ds-card relative min-h-[420px] overflow-hidden lg:sticky lg:top-24 lg:h-[calc(100vh-8rem)] lg:min-h-0 lg:self-start">
                    <CoursesMap
                        v-if="maps.configured"
                        :maps-key="maps.key"
                        :courses="courses"
                        :bounds="bounds"
                        :circle="mapCircle"
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
            </div>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
