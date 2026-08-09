<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Loader2, Search } from '@lucide/vue';

const props = defineProps<{ mapsKey: string | null }>();

const lat = defineModel<number | string | null>('lat');
const lng = defineModel<number | string | null>('lng');

interface PlaceDetails {
    address?: string;
    postal_code?: string;
    phone?: string;
    website?: string;
    name?: string;
    country_code?: string;
    country_name?: string;
    state_code?: string;
    state_name?: string;
    city_candidates: string[];
}

const emit = defineEmits<{
    (e: 'place', details: PlaceDetails): void;
    (e: 'place-cleared'): void;
}>();

const el = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const loading = ref(true);
const failed = ref(false);

/* eslint-disable @typescript-eslint/no-explicit-any */
let g: any = null;
let map: any = null;
let marker: any = null;

const num = (v: unknown) => {
    const n = Number(v);
    return Number.isFinite(n) && v !== '' && v !== null ? n : null;
};

// Google's locality shape varies by country, so collect every plausible city
// name in priority order and let the server try them in turn.
const CITY_TYPES = ['locality', 'postal_town', 'administrative_area_level_2', 'administrative_area_level_3', 'sublocality_level_1'];

function parseComponents(components: any[]): PlaceDetails {
    const pick = (type: string) => components.find((comp) => comp.types?.includes(type));
    const country = pick('country');
    const state = pick('administrative_area_level_1');

    return {
        postal_code: pick('postal_code')?.long_name || undefined,
        country_code: country?.short_name || undefined, // ISO 3166-1 alpha-2
        country_name: country?.long_name || undefined,
        state_code: state?.short_name || undefined,
        state_name: state?.long_name || undefined,
        city_candidates: CITY_TYPES.map((type) => pick(type)?.long_name).filter(Boolean),
    };
}

function placeMarker(la: number, ln: number) {
    if (!map || !g) return;
    if (!marker) {
        marker = new g.maps.Marker({
            map,
            draggable: true,
            position: { lat: la, lng: ln },
            icon: {
                path: g.maps.SymbolPath.CIRCLE,
                scale: 8,
                fillColor: '#8ae63c',
                fillOpacity: 1,
                strokeColor: '#0a1400',
                strokeWeight: 1.6,
            },
        });
        marker.addListener('dragend', (e: any) => {
            setLatLng(e.latLng.lat(), e.latLng.lng(), false);
            emit('place-cleared'); // the pin no longer matches the searched place
        });
    } else {
        marker.setPosition({ lat: la, lng: ln });
    }
}

function setLatLng(la: number, ln: number, recenter = true) {
    lat.value = Number(la.toFixed(7));
    lng.value = Number(ln.toFixed(7));
    placeMarker(la, ln);
    if (recenter && map) {
        map.panTo({ lat: la, lng: ln });
        if (map.getZoom() < 14) map.setZoom(16);
    }
}

onMounted(async () => {
    if (typeof window === 'undefined' || !props.mapsKey) return;
    try {
        const loaderMod = await import('@googlemaps/js-api-loader');
        loaderMod.setOptions({ key: props.mapsKey, v: 'weekly' });
        await loaderMod.importLibrary('maps');
        await loaderMod.importLibrary('marker');
        const placesLib = await loaderMod.importLibrary('places');
        g = (window as any).google;

        const startLat = num(lat.value);
        const startLng = num(lng.value);
        const hasStart = startLat !== null && startLng !== null;

        map = new g.maps.Map(el.value, {
            center: hasStart ? { lat: startLat!, lng: startLng! } : { lat: 39.8, lng: -98.6 },
            zoom: hasStart ? 16 : 4,
            mapTypeId: 'hybrid',
            gestureHandling: 'cooperative', // plain scroll pages; Cmd/Ctrl+scroll zooms
            disableDefaultUI: true,
            zoomControl: true,
            tilt: 0,
        });
        map.addListener('click', (e: any) => {
            setLatLng(e.latLng.lat(), e.latLng.lng(), false);
            emit('place-cleared');
        });
        if (hasStart) placeMarker(startLat!, startLng!);

        // Places search box → jump to an address/course and pull its details.
        const ac = new placesLib.Autocomplete(searchInput.value, {
            fields: ['geometry', 'name', 'formatted_address', 'address_components', 'formatted_phone_number', 'website'],
        });
        ac.addListener('place_changed', () => {
            const place = ac.getPlace();
            const loc = place?.geometry?.location;
            if (!loc) return;
            setLatLng(loc.lat(), loc.lng(), true);

            emit('place', {
                ...parseComponents(place.address_components ?? []),
                address: place.formatted_address || undefined,
                phone: place.formatted_phone_number || undefined,
                website: place.website || undefined,
                name: place.name || undefined,
            });
        });

        loading.value = false;
    } catch (e) {
        console.error('[LocatorMap] Google Maps failed to load', e);
        failed.value = true;
        loading.value = false;
    }
});

// Keep the marker in sync when the numeric fields are edited by hand.
watch([lat, lng], ([la, ln]) => {
    const nl = num(la);
    const ng = num(ln);
    if (nl === null || ng === null || !map) return;
    const pos = marker?.getPosition();
    if (pos && Math.abs(pos.lat() - nl) < 1e-7 && Math.abs(pos.lng() - ng) < 1e-7) return; // no-op
    placeMarker(nl, ng);
    map.panTo({ lat: nl, lng: ng });
    if (map.getZoom() < 14) map.setZoom(16);
});

onBeforeUnmount(() => {
    marker?.setMap(null);
    marker = null;
    map = null;
});
</script>

<template>
    <div>
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-subtle" />
            <input
                ref="searchInput"
                type="text"
                placeholder="Search an address or course to locate it…"
                class="w-full rounded-lg border border-line bg-ink-800 py-2.5 pr-3 pl-9 text-sm text-fg placeholder:text-fg-subtle focus:border-line-lime focus:outline-none"
                autocomplete="off"
            />
        </div>

        <div class="relative mt-3 h-[320px] overflow-hidden rounded-xl border border-line">
            <div ref="el" class="h-full w-full" />
            <div
                v-if="loading || failed"
                class="absolute inset-0 flex items-center justify-center bg-ink-900/80 text-sm text-fg-subtle"
            >
                <span v-if="failed">Map couldn’t load — check the Maps JavaScript API key.</span>
                <Loader2 v-else class="size-5 animate-spin" />
            </div>
        </div>
        <p class="mt-2 text-xs text-fg-subtle">Search, or click / drag the pin to set the course location.</p>
    </div>
</template>

<style>
/* Dark-theme the Google Places autocomplete dropdown (appended to <body>). */
.pac-container {
    background: #111311;
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 10px;
    margin-top: 4px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    font-family: inherit;
}
.pac-item {
    color: #a8b0a6;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    padding: 6px 10px;
}
.pac-item:hover {
    background: #161815;
}
.pac-item-query,
.pac-matched {
    color: #f5f7f4;
}
.pac-icon {
    filter: invert(0.6);
}
.pac-container::after {
    display: none; /* hide the "powered by Google" gap row */
}
</style>
