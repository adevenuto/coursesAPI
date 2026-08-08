<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Flag, Globe, MapPin, Navigation, Pencil } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';

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
    scorecard: { hole_count: number | null; teeboxes: Array<{ name: string | null; total_yards: number | null }> } | null;
    green_centers_available: boolean;
}

const props = defineProps<{ course: Course; canEdit?: boolean }>();

const place = [props.course.location.city, props.course.location.state, props.course.location.country?.name]
    .filter(Boolean)
    .join(', ');
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[880px] px-5 py-10 sm:px-7">
            <Link href="/explorer" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                <ArrowLeft class="size-4" /> Back to explorer
            </Link>

            <div class="mt-6 flex items-start gap-4">
                <span class="ds-icon-tile mt-1 shrink-0"><Flag class="size-5 text-lime-500" /></span>
                <div class="min-w-0">
                    <h1 class="font-display text-3xl font-bold tracking-tight text-fg" style="letter-spacing: -0.02em">
                        {{ course.name }}
                    </h1>
                    <p v-if="course.club && course.club !== course.name" class="mt-1 text-fg-muted">{{ course.club }}</p>
                    <p v-if="place" class="mt-2 flex items-center gap-1.5 text-sm text-fg-subtle">
                        <MapPin class="size-4" /> {{ place }}
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

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <div class="ds-card p-5">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Location</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div v-if="course.address" class="text-fg">{{ course.address }}</div>
                        <div v-if="course.postal_code" class="text-fg-muted">{{ course.postal_code }}</div>
                        <div v-if="course.coordinates.latitude" class="flex items-center gap-1.5 text-fg-subtle">
                            <Navigation class="size-3.5" />
                            {{ course.coordinates.latitude }}, {{ course.coordinates.longitude }}
                        </div>
                    </dl>
                </div>

                <div class="ds-card p-5">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Course</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-fg-subtle">Holes</dt>
                            <dd class="text-fg">{{ course.scorecard?.hole_count ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-fg-subtle">Tee sets</dt>
                            <dd class="text-fg">{{ course.scorecard?.teeboxes?.length ?? 0 }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-fg-subtle">Green-center GPS</dt>
                            <dd :class="course.green_centers_available ? 'text-lime-500' : 'text-fg-subtle'">
                                {{ course.green_centers_available ? 'Available' : '—' }}
                            </dd>
                        </div>
                        <div v-if="course.website" class="flex items-center gap-1.5 pt-1">
                            <Globe class="size-3.5 text-fg-subtle" />
                            <a :href="course.website" target="_blank" rel="noopener" class="truncate text-lime-500 hover:underline">
                                Website
                            </a>
                        </div>
                    </dl>
                </div>
            </div>

            <p class="mt-8 font-mono text-xs text-fg-subtle">
                Full scorecard, teeboxes, and green-center GPS are available via the API.
                <Link href="/docs" class="text-lime-500 hover:underline">Read the docs →</Link>
            </p>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
