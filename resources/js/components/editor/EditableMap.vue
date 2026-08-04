<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Loader2 } from '@lucide/vue';

interface Green {
    hole: number;
    lat: number;
    lng: number;
}

const props = defineProps<{
    mapsKey: string;
    lat: number;
    lng: number;
    greens: Green[];
    activeHole: number;
}>();

const emit = defineEmits<{
    (e: 'map-click', p: { lat: number; lng: number }): void;
    (e: 'marker-drag', p: { hole: number; lat: number; lng: number }): void;
    (e: 'marker-click', hole: number): void;
}>();

/* eslint-disable @typescript-eslint/no-explicit-any */
let g: any = null;
let map: any = null;
let markers: any[] = [];
const el = ref<HTMLElement | null>(null);
const loading = ref(true);
const failed = ref(false);

function clearMarkers() {
    markers.forEach((m) => m.setMap(null));
    markers = [];
}

function renderMarkers() {
    if (!map || !g) return;
    clearMarkers();
    for (const green of props.greens) {
        const isActive = green.hole === props.activeHole;
        const marker = new g.maps.Marker({
            map,
            position: { lat: green.lat, lng: green.lng },
            draggable: true,
            zIndex: isActive ? 999 : 1,
            icon: {
                path: g.maps.SymbolPath.CIRCLE,
                scale: isActive ? 13 : 11,
                fillColor: isActive ? '#b6f16e' : '#8ae63c',
                fillOpacity: 1,
                strokeColor: '#0a1400',
                strokeWeight: 1.5,
            },
            label: { text: String(green.hole), color: '#0a1400', fontSize: '11px', fontWeight: '700' },
        });
        marker.addListener('click', () => emit('marker-click', green.hole));
        marker.addListener('dragend', (e: any) =>
            emit('marker-drag', { hole: green.hole, lat: e.latLng.lat(), lng: e.latLng.lng() }),
        );
        markers.push(marker);
    }
}

onMounted(async () => {
    if (typeof window === 'undefined' || !props.mapsKey) return;
    try {
        const loaderMod = await import('@googlemaps/js-api-loader');
        loaderMod.setOptions({ key: props.mapsKey, v: 'weekly' });
        await loaderMod.importLibrary('maps');
        await loaderMod.importLibrary('marker');
        g = (window as any).google;

        map = new g.maps.Map(el.value, {
            center: { lat: props.lat, lng: props.lng },
            zoom: 16,
            mapTypeId: 'hybrid', // satellite + labels so greens are visible
            gestureHandling: 'cooperative', // plain scroll pages; Cmd/Ctrl+scroll zooms
            disableDefaultUI: true,
            zoomControl: true,
            tilt: 0,
        });
        map.addListener('click', (e: any) => emit('map-click', { lat: e.latLng.lat(), lng: e.latLng.lng() }));

        loading.value = false;
        renderMarkers();
    } catch (e) {
        console.error('[EditableMap] Google Maps failed to load', e);
        failed.value = true;
        loading.value = false;
    }
});

// Re-draw markers when greens or the active hole change.
watch([() => props.greens, () => props.activeHole], renderMarkers, { deep: true });

// Pan to a hole's green when it's selected (if it has one placed).
watch(() => props.activeHole, (hole) => {
    const green = props.greens.find((gc) => gc.hole === hole);
    if (map && green) map.panTo({ lat: green.lat, lng: green.lng });
});

// Recenter if the course coordinates change materially and no greens exist yet.
watch([() => props.lat, () => props.lng], ([lat, lng]) => {
    if (map && props.greens.length === 0) map.setCenter({ lat, lng });
});

onBeforeUnmount(() => {
    clearMarkers();
    map = null;
});
</script>

<template>
    <div class="relative h-full w-full">
        <div ref="el" class="h-full w-full" />
        <div
            v-if="loading || failed"
            class="absolute inset-0 flex items-center justify-center bg-ink-900/80 text-sm text-fg-subtle"
        >
            <span v-if="failed">Map couldn’t load — check the Maps JavaScript API key.</span>
            <Loader2 v-else class="size-5 animate-spin" />
        </div>
    </div>
</template>
