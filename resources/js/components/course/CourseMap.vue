<script setup lang="ts">
import { ref, watch } from 'vue';
import { Loader2, MapPin } from '@lucide/vue';
import { useInView } from '@/composables/useInView';

/**
 * A small locator map for a course.
 *
 * Loaded only once it scrolls into view. This sits on ~22,000 public pages that
 * take organic search traffic, and the Maps JS API bills per load — so a map
 * nobody scrolls to should not be a map anybody pays for.
 *
 * Degrades rather than breaks: no key, no coordinates, or a failed script all
 * end at the same place, with the page still printing the coordinates itself.
 */
const props = defineProps<{
    lat: number | null;
    lng: number | null;
    mapsKey: string;
    configured: boolean;
    label?: string | null;
    /** Extent of the course's mapped greens, when it has any. */
    bounds?: { min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null;
}>();

/* eslint-disable @typescript-eslint/no-explicit-any */
const el = ref<HTMLElement | null>(null);
const loading = ref(true);
const failed = ref(false);
let map: any = null;

const { target, inView } = useInView(0.1);

const plottable = () => props.configured && props.lat !== null && props.lng !== null;

watch(inView, async (visible) => {
    if (! visible || map || ! plottable() || typeof window === 'undefined') return;

    try {
        const loaderMod = await import('@googlemaps/js-api-loader');
        loaderMod.setOptions({ key: props.mapsKey, v: 'weekly' });
        await loaderMod.importLibrary('maps');
        await loaderMod.importLibrary('marker');
        const g = (window as any).google;

        map = new g.maps.Map(el.value, {
            center: { lat: props.lat, lng: props.lng },
            zoom: 15,
            mapTypeId: 'hybrid', // a golf course is unreadable on the road map
            gestureHandling: 'cooperative', // don't hijack page scroll
            disableDefaultUI: true,
            tilt: 0,
        });

        // Frame the course itself where its extent is known, so the map opens on
        // the property rather than on an arbitrary radius around one point.
        // The zoom above is only the fallback for a course with no mapped greens.
        if (props.bounds) {
            map.fitBounds(
                new g.maps.LatLngBounds(
                    { lat: props.bounds.min_lat, lng: props.bounds.min_lng },
                    { lat: props.bounds.max_lat, lng: props.bounds.max_lng },
                ),
                24,
            );
        }

        // Legacy Marker, matching EditableMap/CoursesMap/LocatorMap.
        // AdvancedMarkerElement requires a Map ID on the map it is added to and
        // throws without one — which lands in the catch below and reports
        // "Map unavailable" over a map that had in fact loaded.
        new g.maps.Marker({
            map,
            position: { lat: props.lat, lng: props.lng },
            title: props.label ?? undefined,
        });

        loading.value = false;
    } catch (e) {
        console.error('[CourseMap] Google Maps failed to load', e);
        failed.value = true;
        loading.value = false;
    }
});
</script>

<template>
    <div
        v-if="configured && lat !== null && lng !== null"
        ref="target"
        class="relative h-44 w-full overflow-hidden rounded-xl border border-line bg-ink-800"
    >
        <div ref="el" class="size-full" />

        <div
            v-if="loading || failed"
            class="pointer-events-none absolute inset-0 flex items-center justify-center gap-2 text-xs text-fg-subtle"
        >
            <template v-if="failed">
                <MapPin class="size-4" /> Map unavailable
            </template>
            <template v-else>
                <Loader2 class="size-4 animate-spin" />
            </template>
        </div>
    </div>
</template>
