<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Flag, Loader2, MapPin } from '@lucide/vue';

interface ResultCourse {
    id: number;
    name: string;
    club: string | null;
    city: string | null;
    state: string | null;
    distance_mi?: number;
    url: string;
}

const props = defineProps<{
    area: { type: string; name: string; label: string; radius_mi?: number } | null;
    courses: ResultCourse[];
    count: number;
    loaded?: number; // full loaded set size (courses is the map-visible subset)
    capped: boolean;
    loading: boolean;
    refreshing?: boolean;
    hoveredId?: number | null;
}>();

const emit = defineEmits<{ (e: 'hover', id: number | null): void }>();

const PER_PAGE = 20;
const page = ref(1);
const topAnchor = ref<HTMLElement | null>(null);

const total = computed(() => props.courses.length);
const loadedTotal = computed(() => props.loaded ?? props.courses.length);
const filtered = computed(() => props.courses.length < loadedTotal.value);
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / PER_PAGE)));
const start = computed(() => (page.value - 1) * PER_PAGE);
const paged = computed(() => props.courses.slice(start.value, start.value + PER_PAGE));
const rangeStart = computed(() => (total.value ? start.value + 1 : 0));
const rangeEnd = computed(() => Math.min(start.value + PER_PAGE, total.value));

// A fresh result set resets to page 1.
watch(() => props.courses, () => (page.value = 1));

function go(next: number) {
    page.value = Math.min(pageCount.value, Math.max(1, next));
    topAnchor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <div class="relative">
        <div ref="topAnchor" class="scroll-mt-24" />

        <div v-if="loading" class="space-y-2">
            <div v-for="i in 6" :key="i" class="h-16 animate-pulse rounded-xl border border-line bg-ink-800" />
        </div>

        <template v-else-if="area">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate font-display text-lg font-semibold text-fg">{{ area.label }}</h2>
                    <p class="mt-0.5 font-mono text-[11px] text-fg-subtle tabular-nums">
                        <template v-if="filtered">
                            {{ total.toLocaleString() }} in view · {{ count.toLocaleString() }} total
                        </template>
                        <template v-else-if="area.radius_mi">
                            {{ count.toLocaleString() }} course{{ count === 1 ? '' : 's' }} within {{ area.radius_mi }} mi<template v-if="capped"> · first {{ total.toLocaleString() }}</template>
                        </template>
                        <template v-else-if="capped">
                            {{ count.toLocaleString() }} courses · exploring the first {{ total.toLocaleString() }}
                        </template>
                        <template v-else>{{ count.toLocaleString() }} course{{ count === 1 ? '' : 's' }}</template>
                    </p>
                </div>

                <div v-if="pageCount > 1" class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted transition enabled:cursor-pointer enabled:hover:border-line-lime enabled:hover:text-fg disabled:opacity-40"
                        :disabled="page === 1"
                        aria-label="Previous page"
                        @click="go(page - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="font-mono text-xs text-fg-subtle tabular-nums">
                        {{ rangeStart.toLocaleString() }}–{{ rangeEnd.toLocaleString() }} of {{ total.toLocaleString() }}
                    </span>
                    <button
                        type="button"
                        class="grid size-7 place-items-center rounded-lg border border-line text-fg-muted transition enabled:cursor-pointer enabled:hover:border-line-lime enabled:hover:text-fg disabled:opacity-40"
                        :disabled="page === pageCount"
                        aria-label="Next page"
                        @click="go(page + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>

            <div v-if="paged.length" class="space-y-2">
                <Link
                    v-for="course in paged"
                    :key="course.id"
                    :href="course.url"
                    class="ds-card ds-card--hover flex items-center gap-3 p-4"
                    :class="hoveredId === course.id ? 'bg-ink-800 ring-1 ring-line-lime' : ''"
                    @mouseenter="emit('hover', course.id)"
                    @mouseleave="emit('hover', null)"
                >
                    <span class="ds-icon-tile shrink-0">
                        <Flag class="size-4 text-lime-500" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-fg">{{ course.name }}</span>
                        <span v-if="course.club && course.club !== course.name" class="block truncate text-xs text-fg-muted">
                            {{ course.club }}
                        </span>
                        <span class="flex items-center gap-1 text-xs text-fg-subtle">
                            <MapPin class="size-3 shrink-0" />
                            <span class="truncate">{{ [course.city, course.state].filter(Boolean).join(', ') || '—' }}</span>
                        </span>
                    </span>
                    <span
                        v-if="course.distance_mi != null"
                        class="shrink-0 self-center font-mono text-xs text-fg-subtle tabular-nums"
                    >
                        {{ course.distance_mi }} mi
                    </span>
                </Link>
            </div>
            <p v-else class="rounded-xl border border-line bg-ink-800 p-6 text-center text-sm text-fg-subtle">
                <template v-if="filtered">No courses in this map view — zoom out to see more.</template>
                <template v-else>No mapped courses in this area yet.</template>
            </p>
        </template>

        <div v-else class="rounded-xl border border-dashed border-line p-10 text-center">
            <p class="text-sm text-fg-muted">Search above to explore courses by place.</p>
            <p class="mt-1 text-xs text-fg-subtle">Pick a city, state, or country to list its courses.</p>
        </div>

        <!-- in-place refresh overlay (radius toggle / slider) -->
        <div
            v-if="refreshing"
            class="pointer-events-none absolute inset-0 z-10 flex items-start justify-center rounded-xl bg-ink-900/40 pt-20"
        >
            <Loader2 class="size-5 animate-spin text-fg-muted" />
        </div>
    </div>
</template>
