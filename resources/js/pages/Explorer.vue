<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { MapPinned } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import GlowBadge from '@/components/marketing/GlowBadge.vue';
import CourseSearch from '@/components/explorer/CourseSearch.vue';
import ResultsList from '@/components/explorer/ResultsList.vue';

interface Hit {
    id: number;
    type: 'course' | 'city' | 'state' | 'country';
    name?: string;
    label?: string;
    url: string;
}

const props = defineProps<{
    algolia: {
        app_id: string;
        search_key: string;
        configured: boolean;
        indices: { courses: string; cities: string; states: string; countries: string };
    };
    baseUrl: string;
}>();

const area = ref<{ type: string; name: string; label: string } | null>(null);
const courses = ref<Array<{ id: number; name: string; club: string | null; city: string | null; state: string | null; url: string }>>([]);
const count = ref(0);
const capped = ref(false);
const loading = ref(false);
// Stashed for Part 2 (map zoom-to-bounds).
const bounds = ref<{ min_lat: number; max_lat: number; min_lng: number; max_lng: number } | null>(null);

async function onSelect(hit: Hit) {
    // A course goes straight to its detail page.
    if (hit.type === 'course') {
        router.visit(hit.url);
        return;
    }

    // A geo loads that area's courses (and bounds for the map).
    loading.value = true;
    area.value = null;
    try {
        const res = await fetch(hit.url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        area.value = data.area;
        courses.value = data.courses ?? [];
        count.value = data.count ?? 0;
        capped.value = !!data.capped;
        bounds.value = data.bounds ?? null;
    } catch {
        area.value = { type: hit.type, name: hit.name ?? '', label: hit.label ?? '' };
        courses.value = [];
        count.value = 0;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head title="Course Explorer — GCA">
        <meta name="description" content="Search 22,000+ golf courses by name, city, state, or country." />
    </Head>

    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1280px] px-5 pt-10 pb-16 sm:px-7">
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
                    <ResultsList
                        :area="area"
                        :courses="courses"
                        :count="count"
                        :capped="capped"
                        :loading="loading"
                    />
                </div>

                <!-- right: map (Part 2) — sticky as the results list scrolls -->
                <div class="ds-card relative min-h-[420px] overflow-hidden lg:sticky lg:top-24 lg:h-[calc(100vh-8rem)] lg:min-h-0 lg:self-start">
                    <div class="aurora absolute inset-0 opacity-40" />
                    <div class="relative flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <span class="ds-icon-tile">
                            <MapPinned class="size-5 text-lime-500" />
                        </span>
                        <p class="font-display text-lg font-semibold text-fg">
                            <template v-if="area">{{ count.toLocaleString() }} courses in {{ area.name }}</template>
                            <template v-else>Interactive map</template>
                        </p>
                        <p class="max-w-xs text-sm text-fg-subtle">
                            Clustered course markers and zoom-to-area land here next.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
