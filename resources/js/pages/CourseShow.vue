<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Flag, Globe, MapPin, Navigation, Pencil, Satellite } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import CourseScorecard, { type ScorecardTee } from '@/components/course/CourseScorecard.vue';
import CourseMap from '@/components/course/CourseMap.vue';
import NearbyCourses, { type Nearby } from '@/components/course/NearbyCourses.vue';
import PhotoPlaceholder from '@/components/course/PhotoPlaceholder.vue';
import type { TeeColorConfig } from '@/lib/teeColor';
import { nf } from '@/lib/format';

interface Course {
    id: number;
    name: string;
    club: string | null;
    address: string | null;
    postal_code: string | null;
    phone: string | null;
    website: string | null;
    location: {
        city: string | null;
        state: string | null;
        country: { name: string; iso2: string } | null;
    };
    coordinates: { latitude: number | null; longitude: number | null };
    scorecard: { hole_count: number | null; teeboxes: ScorecardTee[] } | null;
    green_centers_available: boolean;
}

const props = defineProps<{
    course: Course;
    canEdit?: boolean;
    maps: { key: string; configured: boolean };
    teeColors: TeeColorConfig;
    bounds: { min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null;
    nearby: Nearby | null;
}>();

const place = [props.course.location.city, props.course.location.state, props.course.location.country?.name]
    .filter(Boolean)
    .join(', ');

const tees = computed(() => props.course.scorecard?.teeboxes ?? []);

/** Par comes off the longest tee: it's the one every card prints against. */
const par = computed(() => {
    const longest = [...tees.value].sort((a, b) => (b.total_yards ?? -1) - (a.total_yards ?? -1))[0];
    const holes = longest?.holes ?? [];
    if (holes.length === 0 || holes.some((h) => h.par === null)) return null;

    return holes.reduce((n, h) => n + (h.par ?? 0), 0);
});

const longestYards = computed(() => {
    const totals = tees.value.map((t) => t.total_yards).filter((n): n is number => n !== null);

    return totals.length ? Math.max(...totals) : null;
});

/** Stand-in course photography; see PhotoPlaceholder for why it's labelled. */
const placeholderPhotos = [
    { src: '/images/course-placeholders/course-1.jpg', alt: 'Bunker complex on a golf course at dusk' },
    { src: '/images/course-placeholders/course-2.jpg', alt: 'Aerial view of a green beside a bunker and water' },
    { src: '/images/course-placeholders/course-3.jpg', alt: 'Tree-lined fairway in evening light' },
];

/** Only the facts this course actually carries — an empty tile says nothing. */
const facts = computed(() =>
    [
        { label: 'Holes', value: props.course.scorecard?.hole_count?.toString() ?? null },
        { label: 'Par', value: par.value?.toString() ?? null },
        { label: 'Tee sets', value: tees.value.length ? String(tees.value.length) : null },
        { label: 'Longest', value: longestYards.value ? `${nf(longestYards.value)} yds` : null },
    ].filter((f): f is { label: string; value: string } => f.value !== null),
);
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <!-- Hero -->
        <header class="aurora border-b border-line">
            <div class="mx-auto max-w-[880px] px-5 py-10 sm:px-7">
                <Link href="/explorer" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                    <ArrowLeft class="size-4" /> Back to explorer
                </Link>

                <div class="mt-6 flex items-start gap-4">
                    <span class="ds-icon-tile mt-1 shrink-0"><Flag class="size-5 text-lime-500" /></span>
                    <div class="min-w-0">
                        <h1
                            class="font-display text-3xl font-bold tracking-tight text-fg sm:text-4xl"
                            style="letter-spacing: -0.02em"
                        >
                            {{ course.name }}
                        </h1>
                        <p v-if="course.club && course.club !== course.name" class="mt-1 text-fg-muted">
                            {{ course.club }}
                        </p>
                        <p v-if="place" class="mt-2 flex items-center gap-1.5 text-sm text-fg-subtle">
                            <MapPin class="size-4 shrink-0" /> {{ place }}
                        </p>
                    </div>

                    <Link
                        v-if="canEdit"
                        :href="`/courses/${course.id}/edit`"
                        class="ds-btn ds-btn--dark ml-auto shrink-0 gap-1.5 !px-3 !py-2 text-sm"
                    >
                        <Pencil class="size-4" /> Edit
                    </Link>
                </div>

                <!-- Fact strip -->
                <dl v-if="facts.length || course.green_centers_available" class="mt-7 flex flex-wrap gap-x-8 gap-y-4">
                    <div v-for="fact in facts" :key="fact.label" class="min-w-0">
                        <dt class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">
                            {{ fact.label }}
                        </dt>
                        <dd class="mt-1 font-display text-2xl font-bold text-fg">{{ fact.value }}</dd>
                    </div>
                    <div v-if="course.green_centers_available" class="min-w-0">
                        <dt class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Green GPS</dt>
                        <dd class="mt-1 flex items-center gap-1.5 font-display text-2xl font-bold text-lime-500">
                            <Satellite class="size-5" /> Mapped
                        </dd>
                    </div>
                </dl>
            </div>
        </header>

        <div class="mx-auto max-w-[880px] px-5 pb-14 sm:px-7">
            <!-- Location + contact -->
            <div class="mt-10 grid gap-x-10 gap-y-8 sm:grid-cols-2">
                <div class="min-w-0">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Location</h2>

                    <CourseMap
                        class="mt-3"
                        :lat="course.coordinates.latitude"
                        :lng="course.coordinates.longitude"
                        :maps-key="maps.key"
                        :configured="maps.configured"
                        :bounds="bounds"
                        :label="course.name"
                    />

                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div v-if="course.address" class="text-fg">{{ course.address }}</div>
                        <div v-if="course.postal_code" class="text-fg-muted">{{ course.postal_code }}</div>
                        <div v-if="course.coordinates.latitude" class="flex items-center gap-1.5 text-fg-subtle">
                            <Navigation class="size-3.5 shrink-0" />
                            {{ course.coordinates.latitude }}, {{ course.coordinates.longitude }}
                        </div>
                        <div v-if="!course.address && !course.coordinates.latitude" class="text-fg-subtle">
                            No address on record.
                        </div>
                    </dl>
                </div>

                <div class="min-w-0">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Contact</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div v-if="course.phone" class="text-fg">{{ course.phone }}</div>
                        <div v-if="course.website" class="flex items-center gap-1.5">
                            <Globe class="size-3.5 shrink-0 text-fg-subtle" />
                            <a
                                :href="course.website"
                                target="_blank"
                                rel="noopener"
                                class="truncate text-lime-500 hover:underline"
                            >
                                Website
                            </a>
                        </div>
                        <div v-if="!course.phone && !course.website" class="text-fg-subtle">
                            No contact details on record.
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Images: deliberately inert. There is no photo data behind this
                 yet, and stock imagery on a data product would misrepresent what
                 is actually held — so it states the absence instead. -->
            <section class="mt-12">
                <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Photos</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <PhotoPlaceholder
                        v-for="photo in placeholderPhotos"
                        :key="photo.src"
                        :src="photo.src"
                        :alt="photo.alt"
                    />
                </div>
                <!-- Says plainly that these are stock. Without the label, three
                     course photos under a course's name assert something the
                     data does not support. -->
                <p class="mt-3 font-mono text-xs text-fg-subtle">
                    Stock imagery, not this course — photos coming soon. Via
                    <a
                        href="https://unsplash.com"
                        target="_blank"
                        rel="noopener"
                        class="text-fg-muted hover:text-lime-500 hover:underline"
                    >Unsplash</a>.
                </p>
            </section>

            <CourseScorecard :scorecard="course.scorecard" :tee-colors="teeColors" />

            <div class="mt-12">
                <NearbyCourses :nearby="nearby" />
            </div>

            <p class="mt-10 font-mono text-xs text-fg-subtle">
                Per-hole green-center GPS and bulk access to all
                <span class="text-fg-muted">22,000+</span> courses are available via the API.
                <Link href="/docs" class="text-lime-500 hover:underline">Read the docs →</Link>
            </p>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
