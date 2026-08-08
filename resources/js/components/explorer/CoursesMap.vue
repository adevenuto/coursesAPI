<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Loader2, MapPinned } from '@lucide/vue';

interface MapCourse {
    id: number;
    name: string;
    club: string | null;
    city: string | null;
    state: string | null;
    lat: number;
    lng: number;
    url: string;
}

const props = defineProps<{
    mapsKey: string;
    courses: MapCourse[];
    bounds: { min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null;
    circle?: { lat: number; lng: number; radiusMeters: number } | null;
    hoveredId?: number | null;
}>();

const emit = defineEmits<{
    (e: 'viewport', bounds: { min_lat: number; max_lat: number; min_lng: number; max_lng: number }): void;
    (e: 'marker-hover', id: number | null): void;
}>();

const el = ref<HTMLElement | null>(null);
const loading = ref(true);
const failed = ref(false);

// Google objects are kept out of Vue's reactivity on purpose.
/* eslint-disable @typescript-eslint/no-explicit-any */
let g: any = null;
let map: any = null;
let clusterer: any = null;
let info: any = null;
let circleObj: any = null;
let markers: any[] = [];
let markerById = new Map<number, any>();
let lastHovered: number | null = null;
let MarkerClusterer: any = null;

const baseIcon = () => ({
    path: g.maps.SymbolPath.CIRCLE,
    scale: 6,
    fillColor: '#8ae63c',
    fillOpacity: 1,
    strokeColor: '#0a1400',
    strokeWeight: 1.4,
});
const hoverIcon = () => ({
    path: g.maps.SymbolPath.CIRCLE,
    scale: 9,
    fillColor: '#b6f16e',
    fillOpacity: 1,
    strokeColor: '#0a1400',
    strokeWeight: 1.6,
});

// A restrained dark style tuned to the ink canvas.
const DARK_STYLE = [
    { elementType: 'geometry', stylers: [{ color: '#0e120e' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#6b726a' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#0a0b0a' }] },
    { featureType: 'administrative', elementType: 'geometry', stylers: [{ color: '#2a2f2a' }] },
    { featureType: 'administrative.country', elementType: 'labels.text.fill', stylers: [{ color: '#8a938a' }] },
    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#191d19' }] },
    { featureType: 'road', elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#05070a' }] },
    { featureType: 'landscape.natural', elementType: 'geometry', stylers: [{ color: '#11150f' }] },
];

const escapeHtml = (s: string) =>
    s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string);

function infoHtml(c: MapCourse): string {
    const loc = [c.city, c.state].filter(Boolean).join(', ');
    return `<div style="font-family:system-ui,sans-serif;min-width:150px;color:#0a0b0a">
        <div style="font-weight:700;font-size:13px;line-height:1.25">${escapeHtml(c.name)}</div>
        ${loc ? `<div style="font-size:12px;color:#5a615a;margin-top:1px">${escapeHtml(loc)}</div>` : ''}
        <a href="${c.url}" style="display:inline-block;margin-top:6px;font-size:12px;font-weight:600;color:#3c8f10;text-decoration:none">View course →</a>
    </div>`;
}

onMounted(async () => {
    if (typeof window === 'undefined' || !props.mapsKey) return;
    try {
        const [loaderMod, clustererMod] = await Promise.all([
            import('@googlemaps/js-api-loader'),
            import('@googlemaps/markerclusterer'),
        ]);
        MarkerClusterer = clustererMod.MarkerClusterer;

        // js-api-loader v2: functional API (setOptions + importLibrary).
        loaderMod.setOptions({ key: props.mapsKey, v: 'weekly' });
        await loaderMod.importLibrary('maps');
        await loaderMod.importLibrary('marker');
        g = (window as any).google;

        map = new g.maps.Map(el.value, {
            center: { lat: 24, lng: 4 },
            zoom: 2,
            styles: DARK_STYLE,
            disableDefaultUI: true,
            zoomControl: true,
            backgroundColor: '#0a0b0a',
            minZoom: 2,
            // Cooperative: a plain scroll moves the page rather than zooming the
            // map, so the map can't trap the scroll when it fills the screen on
            // mobile. Pinch, or Cmd/Ctrl + scroll, still zooms. Matches the
            // editor maps.
            gestureHandling: 'cooperative',
        });
        info = new g.maps.InfoWindow();
        // Report the viewport whenever a pan/zoom settles (incl. fitBounds).
        map.addListener('idle', emitViewport);
        loading.value = false;
        render();
    } catch (e) {
        console.error('[CoursesMap] Google Maps failed to load', e);
        failed.value = true;
        loading.value = false;
    }
});

function clearMarkers() {
    if (clusterer) clusterer.clearMarkers();
    markers.forEach((m) => m.setMap(null));
    markers = [];
    markerById.clear();
    lastHovered = null;
}

function emitViewport() {
    if (!map) return;
    const b = map.getBounds();
    if (!b) return;
    const sw = b.getSouthWest();
    const ne = b.getNorthEast();
    emit('viewport', { min_lat: sw.lat(), max_lat: ne.lat(), min_lng: sw.lng(), max_lng: ne.lng() });
}

function drawCircle() {
    if (circleObj) {
        circleObj.setMap(null);
        circleObj = null;
    }
    if (props.circle && map && g) {
        circleObj = new g.maps.Circle({
            map,
            center: { lat: props.circle.lat, lng: props.circle.lng },
            radius: props.circle.radiusMeters,
            strokeColor: '#8ae63c',
            strokeOpacity: 0.5,
            strokeWeight: 1.5,
            fillColor: '#8ae63c',
            fillOpacity: 0.07,
            clickable: false,
        });
    }
}

function render() {
    if (!map || !g) return;
    clearMarkers();
    drawCircle();

    if (props.courses.length) {
        markers = props.courses.map((c) => {
            const marker = new g.maps.Marker({
                position: { lat: c.lat, lng: c.lng },
                icon: props.hoveredId === c.id ? hoverIcon() : baseIcon(),
                zIndex: props.hoveredId === c.id ? 1000 : 1,
            });
            marker.addListener('click', () => {
                info.setContent(infoHtml(c));
                info.open({ anchor: marker, map });
            });
            marker.addListener('mouseover', () => emit('marker-hover', c.id));
            marker.addListener('mouseout', () => emit('marker-hover', null));
            markerById.set(c.id, marker);
            return marker;
        });
        clusterer = new MarkerClusterer({ map, markers });
        lastHovered = props.hoveredId ?? null;
    }

    // Zoom: a circle (radius search) frames the whole radius; otherwise fit the
    // course set; otherwise a single point / the default world view.
    if (circleObj) {
        map.fitBounds(circleObj.getBounds(), 24);
    } else if (props.courses.length && props.bounds) {
        const { min_lat, max_lat, min_lng, max_lng } = props.bounds;
        if ((min_lat === max_lat && min_lng === max_lng) || props.courses.length === 1) {
            map.setCenter({ lat: min_lat, lng: min_lng });
            map.setZoom(12);
        } else {
            map.fitBounds(
                new g.maps.LatLngBounds({ lat: min_lat, lng: min_lng }, { lat: max_lat, lng: max_lng }),
                48,
            );
        }
    } else if (!props.courses.length) {
        map.setCenter({ lat: 24, lng: 4 });
        map.setZoom(2);
    }
}

watch([() => props.courses, () => props.circle], () => render());

// Highlight the hovered course's marker (from the results list or the map).
watch(() => props.hoveredId, (id) => {
    if (!g) return;
    if (lastHovered !== null && lastHovered !== id) {
        const prev = markerById.get(lastHovered);
        if (prev) {
            prev.setIcon(baseIcon());
            prev.setZIndex(1);
        }
    }
    if (id != null) {
        const curr = markerById.get(id);
        if (curr) {
            curr.setIcon(hoverIcon());
            curr.setZIndex(1000);
        }
    }
    lastHovered = id ?? null;
});

onBeforeUnmount(() => {
    clearMarkers();
    if (circleObj) circleObj.setMap(null);
    circleObj = null;
    clusterer = null;
    map = null;
});
</script>

<template>
    <div class="relative h-full w-full">
        <div ref="el" class="h-full w-full" />

        <div
            v-if="loading || failed"
            class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-ink-900/80 p-8 text-center"
        >
            <span class="ds-icon-tile"><MapPinned class="size-5 text-lime-500" /></span>
            <template v-if="failed">
                <p class="font-display text-sm font-semibold text-fg">Map couldn’t load</p>
                <p class="max-w-xs text-xs text-fg-subtle">
                    Check that the Maps JavaScript API is enabled for this key.
                </p>
            </template>
            <Loader2 v-else class="size-5 animate-spin text-fg-subtle" />
        </div>
    </div>
</template>
